<?php
declare(strict_types=1);

namespace WDDTF\Gravity;

use Closure;
use WDDTF\Diagnostics\SessionStore;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class GravityDiagnostics {
    public const INBOX_FILTER = 'gravityflow_columns_inbox_table';
    public const INBOX_FIELD_VALUE_FILTER = 'gravityflow_inbox_field_value';

    private const CURRENT_SESSION_KEY = 'wddtf_gravityflow_inbox_observation_current';
    private const SESSION_TYPE = 'gravityflow_inbox_observation';
    private const SESSION_TTL = 900;
    private const SAMPLE_LIMIT = 20;
    private const TRACE_LIMIT = 10;
    private const TRACE_EVENT_LIMIT = 24;
    private const ASSIGNEE_REF_LIMIT = 20;

    private SessionStore $sessions;
    private Closure $clock;
    private float $requestStartedAt;
    private ?string $observedSessionId = null;
    private int $inboxHookCalls = 0;
    /** @var array<string, true> */
    private array $inboxCandidateTraceRefs = [];

    public function __construct(
        ?SessionStore $sessions = null,
        ?callable $clock = null,
        ?float $requestStartedAt = null
    ) {
        $this->clock = Closure::fromCallable($clock ?? static fn(): int => time());
        $this->sessions = $sessions ?? new SessionStore($this->clock);
        $this->requestStartedAt = $requestStartedAt
            ?? (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
                ? (float) $_SERVER['REQUEST_TIME_FLOAT']
                : microtime(true));
    }

    public function register(): void {
        add_action('gform_entry_created', [$this, 'observeEntryCreated'], PHP_INT_MAX, 2);
        add_action('gform_after_submission', [$this, 'observeAfterSubmission'], PHP_INT_MAX, 2);
        add_action('gravityflow_step_start', [$this, 'observeStepStart'], PHP_INT_MAX, 5);
        add_filter('gravityflow_step_assignees', [$this, 'observeStepAssignees'], PHP_INT_MAX, 2);
        add_action('gravityflow_step_complete', [$this, 'observeStepComplete'], PHP_INT_MAX, 4);
        add_action('gravityflow_post_process_workflow', [$this, 'observePostProcessWorkflow'], PHP_INT_MAX, 4);
        add_action('gravityflow_workflow_complete', [$this, 'observeWorkflowComplete'], PHP_INT_MAX, 3);
        add_filter(self::INBOX_FILTER, [$this, 'observeInboxRender'], PHP_INT_MAX, 2);
        add_filter(self::INBOX_FIELD_VALUE_FILTER, [$this, 'observeInboxFieldValue'], PHP_INT_MAX, 4);
        add_action('shutdown', [$this, 'persistObservedInboxRequest'], 9998);
    }

    public function startDiagnostic(): array {
        $hosts = $this->hosts();
        if ( empty($hosts['gravity_flow']['available']) ) {
            return [
                'started'     => false,
                'reason'      => 'gravity_flow_unavailable',
                'observation' => $this->currentInboxObservation(),
            ];
        }

        $currentId = get_transient(self::CURRENT_SESSION_KEY);
        if ( is_string($currentId) && '' !== $currentId ) {
            $current = $this->sessions->load($currentId);
            if (
                is_array($current) &&
                self::SESSION_TYPE === ($current['type'] ?? null) &&
                'observing' === ($current['data']['status'] ?? null)
            ) {
                return [
                    'started'     => false,
                    'reason'      => 'already_observing',
                    'observation' => $this->observationFromSession($current),
                ];
            }

            // Stale or completed state is repaired only by this explicit operator action.
            $this->sessions->delete($currentId);
            delete_transient(self::CURRENT_SESSION_KEY);
        } elseif ( false !== $currentId ) {
            delete_transient(self::CURRENT_SESSION_KEY);
        }

        $session = $this->sessions->create(
            self::SESSION_TYPE,
            [
                'status'                  => 'observing',
                'samples'                 => [],
                'sample_count_total'      => 0,
                'last_observed_timestamp' => null,
                'traces'                  => [],
                'trace_order'             => [],
                'traces_truncated'        => false,
            ],
            self::SESSION_TTL
        );

        if ( null === $session ) {
            return [
                'started'     => false,
                'reason'      => 'session_persistence_failed',
                'observation' => $this->emptyObservation('error', 'session_persistence_failed'),
            ];
        }

        if ( ! set_transient(self::CURRENT_SESSION_KEY, $session['id'], self::SESSION_TTL) ) {
            $this->sessions->delete($session['id']);

            return [
                'started'     => false,
                'reason'      => 'current_session_persistence_failed',
                'observation' => $this->emptyObservation('error', 'current_session_persistence_failed'),
            ];
        }

        return [
            'started'     => true,
            'reason'      => 'observing',
            'observation' => $this->observationFromSession($session),
        ];
    }

    public function startInboxObservation(): array {
        return $this->startDiagnostic();
    }

    public function observeEntryCreated(mixed $entry, mixed $form): void {
        $entryId = $this->arrayPositiveInt($entry, 'id');
        $formId = $this->arrayPositiveInt($form, 'id') ?? $this->arrayPositiveInt($entry, 'form_id');
        if ( null === $entryId ) {
            return;
        }

        $this->recordTraceEvent($entryId, $formId, 'entry_created');
    }

    public function observeAfterSubmission(mixed $entry, mixed $form): void {
        $entryId = $this->arrayPositiveInt($entry, 'id');
        $formId = $this->arrayPositiveInt($form, 'id') ?? $this->arrayPositiveInt($entry, 'form_id');
        if ( null === $entryId ) {
            return;
        }

        $this->recordTraceEvent($entryId, $formId, 'submission_completed');
    }

    public function observeStepStart(mixed $stepId, mixed $entryId, mixed $formId, mixed $status, mixed $step): void {
        $entryId = $this->positiveInt($entryId);
        if ( null === $entryId ) {
            return;
        }

        $metadata = [
            'step_ref'    => $this->opaqueRefForCurrentSession('step', $this->positiveInt($stepId)),
            'step_type'   => is_object($step) ? $this->stepString($step, 'get_type') : null,
            'step_status' => $this->safeTechnicalString($status),
        ];

        $this->recordTraceEvent($entryId, $this->positiveInt($formId), 'step_started', $metadata);
    }

    public function observeStepAssignees(mixed $assignees, mixed $step): mixed {
        if ( ! is_array($assignees) || ! is_object($step) ) {
            return $assignees;
        }

        $entryId = $this->stepPositiveInt($step, 'get_entry_id');
        if ( null === $entryId ) {
            return $assignees;
        }

        $session = $this->loadCurrentSession();
        if ( ! is_array($session) || 'observing' !== ($session['data']['status'] ?? null) ) {
            return $assignees;
        }

        $types = [];
        $refs = [];
        foreach ( $assignees as $assignee ) {
            $type = 'unknown';
            $identity = null;

            if ( is_object($assignee) && method_exists($assignee, 'get_key') ) {
                $key = $assignee->get_key();
                if ( is_scalar($key) ) {
                    $key = trim((string) $key);
                    if ( '' !== $key ) {
                        $identity = $key;
                        $separator = strpos($key, '|');
                        if ( false !== $separator ) {
                            $type = $this->safeTechnicalString(substr($key, 0, $separator)) ?? 'unknown';
                        }
                    }
                }
            }

            if ( null === $identity && is_object($assignee) && method_exists($assignee, 'get_id') ) {
                $id = $assignee->get_id();
                if ( is_scalar($id) && '' !== trim((string) $id) ) {
                    $identity = (string) $id;
                }
            }

            $types[$type] = ($types[$type] ?? 0) + 1;
            if ( null !== $identity && count($refs) < self::ASSIGNEE_REF_LIMIT ) {
                $refs[] = $this->opaqueRef($session['id'], 'assignee', $identity);
            }
        }

        ksort($types);
        $stepId = $this->stepPositiveInt($step, 'get_id');
        $metadata = [
            'step_ref'       => null !== $stepId ? $this->opaqueRef($session['id'], 'step', (string) $stepId) : null,
            'step_type'      => $this->stepString($step, 'get_type'),
            'step_status'    => $this->stepString($step, 'get_status'),
            'assignee_count' => count($assignees),
            'assignee_types' => $types,
            'assignee_refs'  => array_values(array_unique($refs)),
            'refs_truncated' => count($assignees) > self::ASSIGNEE_REF_LIMIT,
        ];

        $this->recordTraceEvent(
            $entryId,
            $this->stepPositiveInt($step, 'get_form_id'),
            'assignees_observed',
            $metadata,
            $session
        );

        return $assignees;
    }

    public function observeStepComplete(mixed $stepId, mixed $entryId, mixed $formId, mixed $status = null): void {
        $entryId = $this->positiveInt($entryId);
        if ( null === $entryId ) {
            return;
        }

        $this->recordTraceEvent(
            $entryId,
            $this->positiveInt($formId),
            'step_completed',
            [
                'step_ref'    => $this->opaqueRefForCurrentSession('step', $this->positiveInt($stepId)),
                'step_status' => $this->safeTechnicalString($status),
            ]
        );
    }

    public function observePostProcessWorkflow(mixed $form, mixed $entryId, mixed $stepId, mixed $startingStepId): void {
        $entryId = $this->positiveInt($entryId);
        if ( null === $entryId ) {
            return;
        }

        $this->recordTraceEvent(
            $entryId,
            $this->arrayPositiveInt($form, 'id'),
            'workflow_processed',
            [
                'step_ref'      => $this->opaqueRefForCurrentSession('step', $this->positiveInt($stepId)),
                'next_step_ref' => $this->opaqueRefForCurrentSession('step', $this->positiveInt($startingStepId)),
            ]
        );
    }

    public function observeWorkflowComplete(mixed $entryId, mixed $form, mixed $finalStatus): void {
        $entryId = $this->positiveInt($entryId);
        if ( null === $entryId ) {
            return;
        }

        $this->recordTraceEvent(
            $entryId,
            $this->arrayPositiveInt($form, 'id'),
            'workflow_completed',
            ['workflow_status' => $this->safeTechnicalString($finalStatus)]
        );
    }

    public function observeInboxRender(mixed $columns, mixed $args = null): mixed {
        $session = $this->loadCurrentSession();
        if ( ! is_array($session) || 'observing' !== ($session['data']['status'] ?? null) ) {
            return $columns;
        }

        $this->observedSessionId = $session['id'];
        ++$this->inboxHookCalls;

        return $columns;
    }

    public function observeInboxFieldValue(mixed $value, mixed $formId, mixed $fieldId, mixed $entry): mixed {
        $session = $this->loadCurrentSession();
        if ( ! is_array($session) || 'observing' !== ($session['data']['status'] ?? null) ) {
            return $value;
        }

        $entryId = $this->arrayPositiveInt($entry, 'id');
        if ( null === $entryId ) {
            return $value;
        }

        $traceRef = $this->traceRef($session['id'], $entryId);
        $traces = is_array($session['data']['traces'] ?? null) ? $session['data']['traces'] : [];
        if ( isset($traces[$traceRef]) ) {
            $this->observedSessionId = $session['id'];
            $this->inboxCandidateTraceRefs[$traceRef] = true;
        }

        return $value;
    }

    public function persistObservedInboxRequest(): void {
        if ( null === $this->observedSessionId || $this->inboxHookCalls < 1 ) {
            return;
        }

        $session = $this->sessions->load($this->observedSessionId);
        if (
            ! is_array($session) ||
            self::SESSION_TYPE !== ($session['type'] ?? null) ||
            'observing' !== ($session['data']['status'] ?? null)
        ) {
            return;
        }

        $now = ($this->clock)();
        $samples = is_array($session['data']['samples'] ?? null) ? $session['data']['samples'] : [];
        $total = max(0, (int) ($session['data']['sample_count_total'] ?? 0)) + 1;

        $samples[] = $this->requestSample($now);
        if ( count($samples) > self::SAMPLE_LIMIT ) {
            $samples = array_slice($samples, -self::SAMPLE_LIMIT);
        }

        $session['data']['samples'] = $samples;
        $session['data']['sample_count_total'] = $total;
        $session['data']['last_observed_timestamp'] = $now;

        if ( $total >= self::SAMPLE_LIMIT ) {
            $session['data']['status'] = 'completed';
        }

        $this->sessions->save($session);
    }

    public function snapshot(): array {
        return [
            'hosts'             => $this->hosts(),
            'inbox_observation' => $this->currentInboxObservation(),
        ];
    }

    public function currentInboxObservation(): array {
        $currentId = get_transient(self::CURRENT_SESSION_KEY);
        if ( false === $currentId ) {
            return $this->emptyObservation('not_started');
        }

        if ( ! is_string($currentId) || '' === $currentId ) {
            return $this->emptyObservation('unknown', 'invalid_current_session_pointer');
        }

        $session = $this->sessions->load($currentId);
        if ( ! is_array($session) ) {
            return $this->emptyObservation('unknown', 'expired_or_invalid_session');
        }

        if ( self::SESSION_TYPE !== ($session['type'] ?? null) ) {
            return $this->emptyObservation('unknown', 'unexpected_session_type');
        }

        return $this->observationFromSession($session);
    }

    private function loadCurrentSession(): ?array {
        $currentId = get_transient(self::CURRENT_SESSION_KEY);
        if ( ! is_string($currentId) || '' === $currentId ) {
            return null;
        }

        $session = $this->sessions->load($currentId);
        if ( ! is_array($session) || self::SESSION_TYPE !== ($session['type'] ?? null) ) {
            return null;
        }

        return $session;
    }

    private function recordTraceEvent(
        int $entryId,
        ?int $formId,
        string $eventType,
        array $metadata = [],
        ?array $loadedSession = null
    ): void {
        $session = $loadedSession ?? $this->loadCurrentSession();
        if ( ! is_array($session) || 'observing' !== ($session['data']['status'] ?? null) ) {
            return;
        }

        $traceRef = $this->traceRef($session['id'], $entryId);
        $traces = is_array($session['data']['traces'] ?? null) ? $session['data']['traces'] : [];
        $order = is_array($session['data']['trace_order'] ?? null) ? array_values($session['data']['trace_order']) : [];

        if ( ! isset($traces[$traceRef]) ) {
            if ( count($order) >= self::TRACE_LIMIT ) {
                if ( empty($session['data']['traces_truncated']) ) {
                    $session['data']['traces_truncated'] = true;
                    $this->sessions->save($session);
                }
                return;
            }

            $traces[$traceRef] = [
                'trace_ref'         => $traceRef,
                'form_ref'          => null !== $formId ? $this->opaqueRef($session['id'], 'form', (string) $formId) : null,
                'created_timestamp' => ($this->clock)(),
                'event_count_total' => 0,
                'events_truncated'  => false,
                'events'            => [],
            ];
            $order[] = $traceRef;
        } elseif ( null === ($traces[$traceRef]['form_ref'] ?? null) && null !== $formId ) {
            $traces[$traceRef]['form_ref'] = $this->opaqueRef($session['id'], 'form', (string) $formId);
        }

        $trace = $traces[$traceRef];
        $events = is_array($trace['events'] ?? null) ? array_values($trace['events']) : [];
        $trace['event_count_total'] = max(0, (int) ($trace['event_count_total'] ?? count($events))) + 1;

        if ( count($events) < self::TRACE_EVENT_LIMIT ) {
            $events[] = array_merge(
                $this->baseEvent($eventType),
                array_filter($metadata, static fn(mixed $value): bool => null !== $value)
            );
            $trace['events'] = $events;
        } else {
            $trace['events_truncated'] = true;
        }

        $traces[$traceRef] = $trace;
        $session['data']['traces'] = $traces;
        $session['data']['trace_order'] = $order;
        $this->sessions->save($session);
    }

    private function observationFromSession(array $session): array {
        $data = $session['data'];
        $status = is_string($data['status'] ?? null) && in_array($data['status'], ['observing', 'completed', 'error'], true)
            ? $data['status']
            : 'unknown';
        $samples = is_array($data['samples'] ?? null) ? array_values($data['samples']) : [];
        $total = max(0, (int) ($data['sample_count_total'] ?? count($samples)));
        $ajaxSamples = count(
            array_filter(
                $samples,
                static fn(mixed $sample): bool => is_array($sample) && 'ajax' === ($sample['transport'] ?? null)
            )
        );
        $lastObserved = is_int($data['last_observed_timestamp'] ?? null)
            ? $data['last_observed_timestamp']
            : null;

        $traceMap = is_array($data['traces'] ?? null) ? $data['traces'] : [];
        $traceOrder = is_array($data['trace_order'] ?? null) ? array_values($data['trace_order']) : [];
        $traces = [];
        foreach ( array_slice($traceOrder, 0, self::TRACE_LIMIT) as $traceRef ) {
            if ( ! is_string($traceRef) || ! isset($traceMap[$traceRef]) || ! is_array($traceMap[$traceRef]) ) {
                continue;
            }
            $trace = $traceMap[$traceRef];
            $trace['analysis'] = $this->analyzeTrace($trace, $samples, count($traceOrder));
            $traces[] = $trace;
        }

        return [
            'status'                  => $status,
            'reason'                  => null,
            'session_id'              => $session['id'],
            'created_timestamp'       => $session['created_at'],
            'created_at'              => gmdate('c', $session['created_at']),
            'expires_timestamp'       => $session['expires_at'],
            'expires_at'              => gmdate('c', $session['expires_at']),
            'remaining_seconds'       => max(0, $session['expires_at'] - ($this->clock)()),
            'sample_limit'            => self::SAMPLE_LIMIT,
            'sample_count'            => count($samples),
            'sample_count_total'      => $total,
            'ajax_sample_count'       => $ajaxSamples,
            'truncated'               => $total > count($samples),
            'last_observed_timestamp' => $lastObserved,
            'last_observed_at'        => null !== $lastObserved ? gmdate('c', $lastObserved) : null,
            'samples'                 => $samples,
            'trace_limit'             => self::TRACE_LIMIT,
            'trace_event_limit'       => self::TRACE_EVENT_LIMIT,
            'trace_count'             => count($traces),
            'traces_truncated'        => ! empty($data['traces_truncated']),
            'traces'                  => $traces,
            'analysis'                => $this->analyzeSession($traces, ! empty($data['traces_truncated'])),
            'evidence'                => [
                'observer_hook'                => self::INBOX_FILTER,
                'row_observer_hook'            => self::INBOX_FIELD_VALUE_FILTER,
                'inbox_render_observed'        => $total > 0,
                'ajax_inbox_render_observed'   => $ajaxSamples > 0,
                'causal_lifecycle_observer'    => true,
                'request_duration_measured'    => $total > 0,
                'raw_form_entry_values_stored' => false,
                'raw_host_identifiers_stored'  => false,
                'raw_assignee_identity_stored' => false,
            ],
            'unknowns'                => [
                'client_round_trip_not_measured'         => true,
                'root_cause_not_inferred'                => true,
                'expected_assignee_not_configured'       => true,
                'authentic_host_runtime_not_established' => true,
            ],
        ];
    }

    private function analyzeSession(array $traces, bool $truncated): array {
        if ( $truncated ) {
            return [
                'classification' => 'INSUFFICIENT_EVIDENCE',
                'reason'         => 'candidate_trace_limit_reached',
            ];
        }

        if ( count($traces) > 1 ) {
            return [
                'classification' => 'MULTIPLE_ENTRY_CANDIDATES',
                'reason'         => 'multiple_candidate_entries_observed',
            ];
        }

        if ( 1 === count($traces) ) {
            return $traces[0]['analysis'] ?? ['classification' => 'INSUFFICIENT_EVIDENCE'];
        }

        return [
            'classification' => 'ENTRY_NOT_OBSERVED',
            'reason'         => 'no_candidate_entry_lifecycle_observed',
        ];
    }

    private function analyzeTrace(array $trace, array $samples, int $sessionTraceCount): array {
        $events = is_array($trace['events'] ?? null) ? $trace['events'] : [];
        $types = [];
        $positiveAssignees = false;
        foreach ( $events as $event ) {
            if ( ! is_array($event) || ! is_string($event['type'] ?? null) ) {
                continue;
            }
            $types[$event['type']] = true;
            if ( 'assignees_observed' === $event['type'] && (int) ($event['assignee_count'] ?? 0) > 0 ) {
                $positiveAssignees = true;
            }
        }

        $traceRef = is_string($trace['trace_ref'] ?? null) ? $trace['trace_ref'] : '';
        $linkedInbox = false;
        $linkedAjaxInbox = false;
        foreach ( $samples as $sample ) {
            if ( ! is_array($sample) || ! in_array($traceRef, $sample['candidate_trace_refs'] ?? [], true) ) {
                continue;
            }
            $linkedInbox = true;
            if ( 'ajax' === ($sample['transport'] ?? null) ) {
                $linkedAjaxInbox = true;
            }
        }

        $proven = [
            'entry_created'                => isset($types['entry_created']),
            'submission_completed'         => isset($types['submission_completed']),
            'workflow_observed'            => isset($types['step_started']) || isset($types['assignees_observed']) || isset($types['workflow_processed']) || isset($types['step_completed']) || isset($types['workflow_completed']),
            'step_started'                 => isset($types['step_started']),
            'assignee_evaluation_observed' => isset($types['assignees_observed']),
            'positive_assignee_count'      => $positiveAssignees,
            'step_completed'               => isset($types['step_completed']),
            'workflow_completed'           => isset($types['workflow_completed']),
            'server_inbox_linked'          => $linkedInbox,
            'ajax_inbox_linked'            => $linkedAjaxInbox,
        ];

        if ( ! $proven['entry_created'] ) {
            $classification = 'ENTRY_NOT_OBSERVED';
            $reason = 'entry_created_hook_not_observed';
        } elseif ( ! $proven['submission_completed'] ) {
            $classification = 'SUBMISSION_COMPLETE_NOT_OBSERVED';
            $reason = 'after_submission_hook_not_observed';
        } elseif ( ! $proven['workflow_observed'] ) {
            $classification = 'WORKFLOW_NOT_OBSERVED';
            $reason = 'no_gravity_flow_lifecycle_event_observed';
        } elseif ( ! $proven['step_started'] ) {
            $classification = 'STEP_NOT_OBSERVED';
            $reason = 'step_start_hook_not_observed';
        } elseif ( ! $proven['assignee_evaluation_observed'] ) {
            $classification = 'INSUFFICIENT_EVIDENCE';
            $reason = 'assignee_filter_not_observed';
        } elseif ( ! $proven['positive_assignee_count'] ) {
            $classification = 'NO_ASSIGNEE_OBSERVED';
            $reason = 'assignee_filter_observed_zero_assignees';
        } elseif ( ! $linkedInbox ) {
            $hasInbox = ! empty($samples);
            if ( $hasInbox && $sessionTraceCount > 1 ) {
                $classification = 'MULTIPLE_ENTRY_CANDIDATES';
                $reason = 'inbox_observed_without_unique_candidate_link';
            } elseif ( $hasInbox ) {
                $classification = 'INSUFFICIENT_EVIDENCE';
                $reason = 'inbox_observed_without_row_level_candidate_link';
            } else {
                $classification = 'INBOX_REFRESH_NOT_OBSERVED';
                $reason = 'no_server_inbox_render_observed_after_trace';
            }
        } else {
            $classification = 'TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION';
            $reason = $linkedAjaxInbox ? 'ajax_inbox_row_link_observed' : 'server_inbox_row_link_observed';
        }

        return [
            'classification' => $classification,
            'reason'         => $reason,
            'proven'         => $proven,
            'unknowns'       => [
                'root_cause_not_inferred'        => true,
                'expected_assignee_not_compared' => true,
                'browser_refresh_not_measured'   => true,
                'client_network_not_measured'    => true,
            ],
        ];
    }

    private function hosts(): array {
        $gformHookCount = function_exists('did_action') ? (int) did_action('gform_loaded') : 0;
        $gravityFlowHookCount = function_exists('did_action') ? (int) did_action('gravityflow_loaded') : 0;

        $gravityFormsAvailable = class_exists('GFForms', false) || $gformHookCount > 0;
        $gravityFormsVersion = null;
        if ( class_exists('GFForms', false) ) {
            $publicVariables = get_class_vars('GFForms');
            $gravityFormsVersion = $this->versionLabel($publicVariables['version'] ?? null);
        }

        $gravityFlowVersion = defined('GRAVITY_FLOW_VERSION')
            ? $this->versionLabel(constant('GRAVITY_FLOW_VERSION'))
            : null;
        $gravityFlowAvailable = null !== $gravityFlowVersion
            || class_exists('Gravity_Flow', false)
            || $gravityFlowHookCount > 0;

        return [
            'gravity_forms' => [
                'available'            => $gravityFormsAvailable,
                'version'              => $gravityFormsVersion,
                'loaded_hook_observed' => $gformHookCount > 0,
                'loaded_hook_count'    => $gformHookCount,
            ],
            'gravity_flow'  => [
                'available'            => $gravityFlowAvailable,
                'version'              => $gravityFlowVersion,
                'loaded_hook_observed' => $gravityFlowHookCount > 0,
                'loaded_hook_count'    => $gravityFlowHookCount,
            ],
        ];
    }

    private function versionLabel(mixed $value): ?string {
        if ( ! is_scalar($value) ) {
            return null;
        }

        $version = trim((string) $value);
        if ( '' === $version ) {
            return null;
        }

        return 'v' . ltrim($version, 'vV');
    }

    private function requestSample(int $now): array {
        global $wpdb;

        $transport = $this->requestTransport();
        $queryCount = null;
        if ( isset($wpdb) && is_object($wpdb) && isset($wpdb->num_queries) && is_numeric($wpdb->num_queries) ) {
            $queryCount = (int) $wpdb->num_queries;
        }

        return [
            'observed_timestamp'   => $now,
            'observed_at'          => gmdate('c', $now),
            'transport'            => $transport,
            'elapsed_ms'           => round(max(0.0, (microtime(true) - $this->requestStartedAt) * 1000), 2),
            'memory_peak_bytes'    => memory_get_peak_usage(true),
            'db_query_count'       => $queryCount,
            'observer_hook'        => self::INBOX_FILTER,
            'hook_calls'           => $this->inboxHookCalls,
            'candidate_trace_refs' => array_slice(array_keys($this->inboxCandidateTraceRefs), 0, self::TRACE_LIMIT),
        ];
    }

    private function baseEvent(string $type): array {
        $now = ($this->clock)();

        return [
            'type'               => $type,
            'observed_timestamp' => $now,
            'observed_at'        => gmdate('c', $now),
            'transport'          => $this->requestTransport(),
            'elapsed_ms'         => round(max(0.0, (microtime(true) - $this->requestStartedAt) * 1000), 2),
        ];
    }

    private function requestTransport(): string {
        if ( function_exists('wp_doing_ajax') && wp_doing_ajax() ) {
            return 'ajax';
        }
        if ( defined('REST_REQUEST') && REST_REQUEST ) {
            return 'rest';
        }
        if ( defined('DOING_CRON') && DOING_CRON ) {
            return 'cron';
        }
        if ( defined('WP_CLI') && WP_CLI ) {
            return 'wp_cli';
        }
        if ( function_exists('is_admin') && is_admin() ) {
            return 'admin';
        }

        return 'frontend';
    }

    private function traceRef(string $sessionId, int $entryId): string {
        return $this->opaqueRef($sessionId, 'entry', (string) $entryId, 'gt');
    }

    private function opaqueRefForCurrentSession(string $kind, ?int $value): ?string {
        if ( null === $value ) {
            return null;
        }
        $session = $this->loadCurrentSession();
        if ( ! is_array($session) ) {
            return null;
        }

        return $this->opaqueRef($session['id'], $kind, (string) $value);
    }

    private function opaqueRef(string $sessionId, string $kind, string $value, ?string $prefix = null): string {
        $prefix ??= match ($kind) {
            'form' => 'gf',
            'step' => 'gs',
            'assignee' => 'ga',
            default => 'gx',
        };

        return $prefix . '-' . substr(hash_hmac('sha256', $kind . ':' . $value, $sessionId), 0, 16);
    }

    private function positiveInt(mixed $value): ?int {
        if ( is_int($value) ) {
            return $value > 0 ? $value : null;
        }
        if ( is_string($value) && ctype_digit($value) ) {
            $number = (int) $value;
            return $number > 0 ? $number : null;
        }

        return null;
    }

    private function arrayPositiveInt(mixed $value, string $key): ?int {
        if ( ! is_array($value) || ! array_key_exists($key, $value) ) {
            return null;
        }

        return $this->positiveInt($value[$key]);
    }

    private function stepPositiveInt(object $step, string $method): ?int {
        if ( ! method_exists($step, $method) ) {
            return null;
        }

        return $this->positiveInt($step->{$method}());
    }

    private function stepString(object $step, string $method): ?string {
        if ( ! method_exists($step, $method) ) {
            return null;
        }

        return $this->safeTechnicalString($step->{$method}());
    }

    private function safeTechnicalString(mixed $value): ?string {
        if ( ! is_scalar($value) ) {
            return null;
        }

        $value = strtolower(trim((string) $value));
        if ( '' === $value ) {
            return null;
        }

        $value = preg_replace('/[^a-z0-9_.:-]/', '_', $value) ?? '';
        return '' !== $value ? substr($value, 0, 64) : null;
    }

    private function emptyObservation(string $status, ?string $reason = null): array {
        return [
            'status'                  => $status,
            'reason'                  => $reason,
            'session_id'              => null,
            'created_timestamp'       => null,
            'created_at'              => null,
            'expires_timestamp'       => null,
            'expires_at'              => null,
            'remaining_seconds'       => 0,
            'sample_limit'            => self::SAMPLE_LIMIT,
            'sample_count'            => 0,
            'sample_count_total'      => 0,
            'ajax_sample_count'       => 0,
            'truncated'               => false,
            'last_observed_timestamp' => null,
            'last_observed_at'        => null,
            'samples'                 => [],
            'trace_limit'             => self::TRACE_LIMIT,
            'trace_event_limit'       => self::TRACE_EVENT_LIMIT,
            'trace_count'             => 0,
            'traces_truncated'        => false,
            'traces'                  => [],
            'analysis'                => [
                'classification' => 'ENTRY_NOT_OBSERVED',
                'reason'         => 'no_candidate_entry_lifecycle_observed',
            ],
            'evidence'                => [
                'observer_hook'                => self::INBOX_FILTER,
                'row_observer_hook'            => self::INBOX_FIELD_VALUE_FILTER,
                'inbox_render_observed'        => false,
                'ajax_inbox_render_observed'   => false,
                'causal_lifecycle_observer'    => true,
                'request_duration_measured'    => false,
                'raw_form_entry_values_stored' => false,
                'raw_host_identifiers_stored'  => false,
                'raw_assignee_identity_stored' => false,
            ],
            'unknowns'                => [
                'client_round_trip_not_measured'         => true,
                'root_cause_not_inferred'                => true,
                'expected_assignee_not_configured'       => true,
                'authentic_host_runtime_not_established' => true,
            ],
        ];
    }
}
