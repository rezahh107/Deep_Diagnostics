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
    public const BROWSER_EVIDENCE_ACTION = 'wddtf_record_gravity_browser_evidence';
    public const BROWSER_SAMPLE_HEADER = 'X-WDDTF-Gravity-Sample';

    private const CURRENT_SESSION_KEY = 'wddtf_gravityflow_inbox_observation_current';
    private const SESSION_TYPE = 'gravityflow_inbox_observation';
    private const SESSION_TTL = 900;
    private const SAMPLE_LIMIT = 20;
    private const TRACE_LIMIT = 10;
    private const TRACE_EVENT_LIMIT = 24;
    private const ASSIGNEE_REF_LIMIT = 20;
    private const REF_DOMAIN = 'wddtf-gravity-ref:v1';
    private const BROWSER_NONCE_DOMAIN = 'wddtf-gravity-browser:v1';
    private const BROWSER_CAPABILITY = 'gravityflow_inbox';
    private const BROWSER_SCRIPT_HANDLE = 'wddtf-gravity-browser-observer';
    private const BROWSER_UI_WINDOW_MS = 100;

    private SessionStore $sessions;
    private Closure $clock;
    private Closure $headerEmitter;
    private float $requestStartedAt;
    private ?string $observedSessionId = null;
    private int $inboxHookCalls = 0;
    /** @var array<string, true> */
    private array $inboxCandidateTraceRefs = [];
    private bool $browserEnqueueWindow = false;
    private ?string $browserSampleRef = null;
    private bool $browserHeaderEmitted = false;

    public function __construct(
        ?SessionStore $sessions = null,
        ?callable $clock = null,
        ?float $requestStartedAt = null,
        ?callable $headerEmitter = null
    ) {
        $this->clock = Closure::fromCallable($clock ?? static fn(): int => time());
        $this->sessions = $sessions ?? new SessionStore($this->clock);
        $this->requestStartedAt = $requestStartedAt
            ?? (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
                ? (float) $_SERVER['REQUEST_TIME_FLOAT']
                : microtime(true));
        $this->headerEmitter = Closure::fromCallable(
            $headerEmitter ?? static function(string $name, string $value): bool {
                if ( headers_sent() ) {
                    return false;
                }
                header($name . ': ' . $value);
                return true;
            }
        );
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
        add_action('gravityflow_enqueue_admin_scripts', [$this, 'markBrowserEnqueueWindow'], PHP_INT_MAX, 0);
        add_action('gravityflow_enqueue_frontend_scripts', [$this, 'markBrowserEnqueueWindow'], PHP_INT_MAX, 0);
        add_filter('gravityflow_inbox_args', [$this, 'maybeEnqueueBrowserObserver'], PHP_INT_MAX, 1);
        add_action('wp_ajax_' . self::BROWSER_EVIDENCE_ACTION, [$this, 'recordBrowserEvidence']);
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

        $stepId = $this->positiveInt($stepId);
        $stepType = is_object($step) ? $this->stepString($step, 'get_type') : null;
        $stepStatus = $this->safeTechnicalString($status);

        $this->recordTraceEvent(
            $entryId,
            $this->positiveInt($formId),
            'step_started',
            function(string $sessionId) use ($stepId, $stepType, $stepStatus): array {
                return [
                    'step_ref'    => null !== $stepId ? $this->opaqueRef($sessionId, 'step', (string) $stepId) : null,
                    'step_type'   => $stepType,
                    'step_status' => $stepStatus,
                ];
            }
        );
    }

    public function observeStepAssignees(mixed $assignees, mixed $step): mixed {
        if ( ! is_array($assignees) || ! is_object($step) ) {
            return $assignees;
        }

        $entryId = $this->stepPositiveInt($step, 'get_entry_id');
        if ( null === $entryId ) {
            return $assignees;
        }

        $types = [];
        $identities = [];
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
            if ( null !== $identity && count($identities) < self::ASSIGNEE_REF_LIMIT ) {
                $identities[] = $identity;
            }
        }

        ksort($types);
        $stepId = $this->stepPositiveInt($step, 'get_id');
        $stepType = $this->stepString($step, 'get_type');
        $stepStatus = $this->stepString($step, 'get_status');
        $assigneeCount = count($assignees);
        $refsTruncated = $assigneeCount > self::ASSIGNEE_REF_LIMIT;

        $this->recordTraceEvent(
            $entryId,
            $this->stepPositiveInt($step, 'get_form_id'),
            'assignees_observed',
            function(string $sessionId) use ($stepId, $stepType, $stepStatus, $assigneeCount, $types, $identities, $refsTruncated): array {
                $refs = [];
                foreach ( $identities as $identity ) {
                    $ref = $this->opaqueRef($sessionId, 'assignee', $identity);
                    if ( null !== $ref ) {
                        $refs[] = $ref;
                    }
                }

                return [
                    'step_ref'       => null !== $stepId ? $this->opaqueRef($sessionId, 'step', (string) $stepId) : null,
                    'step_type'      => $stepType,
                    'step_status'    => $stepStatus,
                    'assignee_count' => $assigneeCount,
                    'assignee_types' => $types,
                    'assignee_refs'  => array_values(array_unique($refs)),
                    'refs_truncated' => $refsTruncated,
                ];
            }
        );

        return $assignees;
    }

    public function observeStepComplete(mixed $stepId, mixed $entryId, mixed $formId, mixed $status = null): void {
        $entryId = $this->positiveInt($entryId);
        if ( null === $entryId ) {
            return;
        }

        $stepId = $this->positiveInt($stepId);
        $stepStatus = $this->safeTechnicalString($status);
        $this->recordTraceEvent(
            $entryId,
            $this->positiveInt($formId),
            'step_completed',
            function(string $sessionId) use ($stepId, $stepStatus): array {
                return [
                    'step_ref'    => null !== $stepId ? $this->opaqueRef($sessionId, 'step', (string) $stepId) : null,
                    'step_status' => $stepStatus,
                ];
            }
        );
    }

    public function observePostProcessWorkflow(mixed $form, mixed $entryId, mixed $stepId, mixed $startingStepId): void {
        $entryId = $this->positiveInt($entryId);
        if ( null === $entryId ) {
            return;
        }

        $stepId = $this->positiveInt($stepId);
        $startingStepId = $this->positiveInt($startingStepId);
        $this->recordTraceEvent(
            $entryId,
            $this->arrayPositiveInt($form, 'id'),
            'workflow_processed',
            function(string $sessionId) use ($stepId, $startingStepId): array {
                return [
                    'step_ref'      => null !== $stepId ? $this->opaqueRef($sessionId, 'step', (string) $stepId) : null,
                    'next_step_ref' => null !== $startingStepId ? $this->opaqueRef($sessionId, 'step', (string) $startingStepId) : null,
                ];
            }
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
            null,
            ['workflow_status' => $this->safeTechnicalString($finalStatus)]
        );
    }

    public function markBrowserEnqueueWindow(): void {
        $this->browserEnqueueWindow = true;
    }

    public function maybeEnqueueBrowserObserver(mixed $args): mixed {
        if ( ! $this->browserEnqueueWindow ) {
            return $args;
        }

        $session = $this->loadCurrentSession();
        if ( is_array($session) ) {
            $this->enqueueBrowserObserverForSession($session);
        }

        return $args;
    }

    public function observeInboxRender(mixed $columns, mixed $args = null): mixed {
        $session = $this->loadCurrentSession();
        if ( ! is_array($session) || 'observing' !== ($session['data']['status'] ?? null) ) {
            return $columns;
        }

        $this->observedSessionId = $session['id'];
        ++$this->inboxHookCalls;

        if ( $this->browserEnqueueWindow && 'ajax' !== $this->requestTransport() ) {
            $this->enqueueBrowserObserverForSession($session);
        }

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
        if ( null === $traceRef ) {
            return $value;
        }

        $traces = is_array($session['data']['traces'] ?? null) ? $session['data']['traces'] : [];
        if ( isset($traces[$traceRef]) ) {
            $this->observedSessionId = $session['id'];
            $this->inboxCandidateTraceRefs[$traceRef] = true;
            $this->maybeTagBrowserResponse($session['id']);
        }

        return $value;
    }

    public function persistObservedInboxRequest(): void {
        if ( null === $this->observedSessionId || $this->inboxHookCalls < 1 ) {
            return;
        }

        $sessionId = $this->observedSessionId;
        $now = ($this->clock)();
        $sample = $this->requestSample($now);

        $this->sessions->mutate(
            $sessionId,
            static function(array $session) use ($sample, $now): array {
                if (
                    self::SESSION_TYPE !== ($session['type'] ?? null) ||
                    'observing' !== ($session['data']['status'] ?? null)
                ) {
                    return $session;
                }

                $samples = is_array($session['data']['samples'] ?? null) ? $session['data']['samples'] : [];
                $total = max(0, (int) ($session['data']['sample_count_total'] ?? 0)) + 1;
                $samples[] = $sample;
                if ( count($samples) > self::SAMPLE_LIMIT ) {
                    $samples = array_slice($samples, -self::SAMPLE_LIMIT);
                }

                $session['data']['samples'] = $samples;
                $session['data']['sample_count_total'] = $total;
                $session['data']['last_observed_timestamp'] = $now;
                if ( $total >= self::SAMPLE_LIMIT ) {
                    $session['data']['status'] = 'completed';
                }

                return $session;
            }
        );
    }

    public function recordBrowserEvidence(): void {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : '';
        if ( 'POST' !== $method ) {
            wp_send_json_error(['code' => 'post_required'], 405);
        }

        if ( ! $this->browserEvidenceAuthorized() ) {
            wp_send_json_error(['code' => 'forbidden'], 403);
        }

        $sessionId = $this->currentSessionId();
        $session = null !== $sessionId ? $this->sessions->load($sessionId) : null;
        if (
            ! is_array($session) ||
            self::SESSION_TYPE !== ($session['type'] ?? null) ||
            ! in_array($session['data']['status'] ?? null, ['observing', 'completed'], true)
        ) {
            wp_send_json_error(['code' => 'session_unavailable'], 409);
        }

        $nonceResult = check_ajax_referer($this->browserNonceAction($sessionId), 'nonce', false);
        if ( false === $nonceResult ) {
            wp_send_json_error(['code' => 'invalid_nonce'], 403);
        }

        $payload = $this->browserEvidencePayload($_POST);
        if ( null === $payload ) {
            wp_send_json_error(['code' => 'invalid_evidence'], 400);
        }

        $matched = false;
        $duplicate = false;
        $mutated = $this->sessions->mutate(
            $sessionId,
            static function(array $locked) use ($payload, &$matched, &$duplicate): array {
                if (
                    self::SESSION_TYPE !== ($locked['type'] ?? null) ||
                    ! in_array($locked['data']['status'] ?? null, ['observing', 'completed'], true)
                ) {
                    return $locked;
                }

                $samples = is_array($locked['data']['samples'] ?? null) ? array_values($locked['data']['samples']) : [];
                foreach ( $samples as $index => $sample ) {
                    if (
                        ! is_array($sample) ||
                        ! is_string($sample['sample_ref'] ?? null) ||
                        ! hash_equals($sample['sample_ref'], $payload['sample_ref'])
                    ) {
                        continue;
                    }
                    if ( 'ajax' !== ($sample['transport'] ?? null) || empty($sample['candidate_trace_refs']) ) {
                        return $locked;
                    }

                    $matched = true;
                    if ( is_array($sample['browser'] ?? null) ) {
                        $duplicate = true;
                        return $locked;
                    }

                    $sample['browser'] = $payload['browser'];
                    $samples[$index] = $sample;
                    $locked['data']['samples'] = $samples;
                    return $locked;
                }

                return $locked;
            }
        );

        if ( ! $mutated ) {
            wp_send_json_error(['code' => 'evidence_persistence_failed'], 409);
        }
        if ( ! $matched ) {
            wp_send_json_error(['code' => 'unknown_sample'], 404);
        }

        wp_send_json_success([
            'accepted'  => true,
            'duplicate' => $duplicate,
        ]);
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
        $currentId = $this->currentSessionId();
        if ( null === $currentId ) {
            return null;
        }

        $session = $this->sessions->load($currentId);
        if ( ! is_array($session) || self::SESSION_TYPE !== ($session['type'] ?? null) ) {
            return null;
        }

        return $session;
    }

    private function currentSessionId(): ?string {
        $currentId = get_transient(self::CURRENT_SESSION_KEY);
        return is_string($currentId) && '' !== $currentId ? $currentId : null;
    }

    /**
     * @param null|callable(string):array $metadataFactory
     */
    private function recordTraceEvent(
        int $entryId,
        ?int $formId,
        string $eventType,
        ?callable $metadataFactory = null,
        array $staticMetadata = []
    ): void {
        $sessionId = $this->currentSessionId();
        if ( null === $sessionId ) {
            return;
        }

        $this->sessions->mutate(
            $sessionId,
            function(array $session) use ($entryId, $formId, $eventType, $metadataFactory, $staticMetadata): array {
                if (
                    self::SESSION_TYPE !== ($session['type'] ?? null) ||
                    'observing' !== ($session['data']['status'] ?? null)
                ) {
                    return $session;
                }

                $traceRef = $this->traceRef($session['id'], $entryId);
                if ( null === $traceRef ) {
                    return $session;
                }

                $traces = is_array($session['data']['traces'] ?? null) ? $session['data']['traces'] : [];
                $order = is_array($session['data']['trace_order'] ?? null) ? array_values($session['data']['trace_order']) : [];

                if ( ! isset($traces[$traceRef]) ) {
                    if ( count($order) >= self::TRACE_LIMIT ) {
                        $session['data']['traces_truncated'] = true;
                        return $session;
                    }

                    $formRef = null;
                    if ( null !== $formId ) {
                        $formRef = $this->opaqueRef($session['id'], 'form', (string) $formId);
                    }

                    $traces[$traceRef] = [
                        'trace_ref'         => $traceRef,
                        'form_ref'          => $formRef,
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
                    $metadata = $staticMetadata;
                    if ( null !== $metadataFactory ) {
                        $metadata = array_merge($metadata, $metadataFactory($session['id']));
                    }
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
                return $session;
            }
        );
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
        $browserEvidenceCount = count(
            array_filter(
                $samples,
                static fn(mixed $sample): bool => is_array($sample) && is_array($sample['browser'] ?? null)
            )
        );
        $lastObserved = is_int($data['last_observed_timestamp'] ?? null)
            ? $data['last_observed_timestamp']
            : null;
        $integrity = $this->sessions->integrityStatus($session['id']);

        $traceMap = is_array($data['traces'] ?? null) ? $data['traces'] : [];
        $traceOrder = is_array($data['trace_order'] ?? null) ? array_values($data['trace_order']) : [];
        $traces = [];
        foreach ( array_slice($traceOrder, 0, self::TRACE_LIMIT) as $traceRef ) {
            if ( ! is_string($traceRef) || ! isset($traceMap[$traceRef]) || ! is_array($traceMap[$traceRef]) ) {
                continue;
            }
            $trace = $traceMap[$traceRef];
            $trace['analysis'] = $this->analyzeTrace($trace, $samples, count($traceOrder), $integrity);
            $traces[] = $trace;
        }

        $browserAnalysis = $this->analyzeBrowserEvidence($samples, $integrity);

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
            'browser_evidence_count'  => $browserEvidenceCount,
            'truncated'               => $total > count($samples),
            'last_observed_timestamp' => $lastObserved,
            'last_observed_at'        => null !== $lastObserved ? gmdate('c', $lastObserved) : null,
            'samples'                 => $samples,
            'trace_limit'             => self::TRACE_LIMIT,
            'trace_event_limit'       => self::TRACE_EVENT_LIMIT,
            'trace_count'             => count($traces),
            'traces_truncated'        => ! empty($data['traces_truncated']),
            'traces'                  => $traces,
            'integrity'               => $integrity,
            'analysis'                => $this->analyzeSession($traces, ! empty($data['traces_truncated']), $integrity),
            'browser_analysis'        => $browserAnalysis,
            'evidence'                => [
                'observer_hook'                    => self::INBOX_FILTER,
                'row_observer_hook'                => self::INBOX_FIELD_VALUE_FILTER,
                'inbox_render_observed'            => $total > 0,
                'ajax_inbox_render_observed'       => $ajaxSamples > 0,
                'causal_lifecycle_observer'        => true,
                'request_duration_measured'        => $total > 0,
                'browser_observer_available'       => true,
                'browser_response_received'        => $browserEvidenceCount > 0,
                'browser_ui_signal_observed'       => 'BROWSER_UI_SIGNAL_OBSERVED' === ($browserAnalysis['classification'] ?? null),
                'raw_form_entry_values_stored'     => false,
                'raw_host_identifiers_stored'      => false,
                'raw_assignee_identity_stored'     => false,
                'request_response_payloads_stored' => false,
                'generic_ajax_activity_stored'     => false,
                'session_integrity_uncertain'      => ! empty($integrity['uncertain']),
            ],
            'unknowns'                => [
                'client_round_trip_not_measured'              => 0 === $browserEvidenceCount,
                'entry_visible_to_user_not_proven'            => true,
                'root_cause_not_inferred'                     => true,
                'expected_assignee_not_configured'            => true,
                'authentic_host_runtime_not_established'      => true,
                'authentic_live_refresh_not_established'      => true,
                'email_token_assignee_browser_not_qualified'  => true,
                'session_integrity_uncertain'                 => ! empty($integrity['uncertain']),
            ],
        ];
    }

    private function analyzeSession(array $traces, bool $truncated, array $integrity): array {
        if ( $truncated ) {
            return [
                'classification' => 'INSUFFICIENT_EVIDENCE',
                'reason'         => 'candidate_trace_limit_reached',
            ];
        }

        if ( ! empty($integrity['uncertain']) ) {
            return [
                'classification' => 'INSUFFICIENT_EVIDENCE',
                'reason'         => 'session_integrity_uncertain',
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

    private function analyzeTrace(array $trace, array $samples, int $sessionTraceCount, array $integrity): array {
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
        $browserLinked = false;
        foreach ( $samples as $sample ) {
            if ( ! is_array($sample) || ! in_array($traceRef, $sample['candidate_trace_refs'] ?? [], true) ) {
                continue;
            }
            $linkedInbox = true;
            if ( 'ajax' === ($sample['transport'] ?? null) ) {
                $linkedAjaxInbox = true;
            }
            if ( is_array($sample['browser'] ?? null) ) {
                $browserLinked = true;
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
        $eventsTruncated = ! empty($trace['events_truncated']);
        $positiveServerChain = $proven['entry_created']
            && $proven['submission_completed']
            && $proven['step_started']
            && $proven['positive_assignee_count']
            && $proven['server_inbox_linked'];

        if ( ! empty($integrity['uncertain']) ) {
            $classification = 'INSUFFICIENT_EVIDENCE';
            $reason = 'session_integrity_uncertain';
        } elseif ( $eventsTruncated && ! $positiveServerChain ) {
            $classification = 'INSUFFICIENT_EVIDENCE';
            $reason = 'trace_event_limit_reached';
        } elseif ( ! $proven['entry_created'] ) {
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
            'complete_history' => ! $eventsTruncated,
            'events_truncated' => $eventsTruncated,
            'proven'         => $proven,
            'unknowns'       => [
                'root_cause_not_inferred'        => true,
                'expected_assignee_not_compared' => true,
                'browser_refresh_not_measured'   => ! $browserLinked,
                'client_network_not_measured'    => ! $browserLinked,
                'entry_visible_to_user_not_proven' => true,
                'trace_history_incomplete'       => $eventsTruncated,
                'session_integrity_uncertain'    => ! empty($integrity['uncertain']),
            ],
        ];
    }

    private function analyzeBrowserEvidence(array $samples, array $integrity): array {
        if ( ! empty($integrity['uncertain']) ) {
            return [
                'classification' => 'BROWSER_EVIDENCE_INSUFFICIENT',
                'reason'         => 'session_integrity_uncertain',
                'entry_visible_to_user_proven' => false,
            ];
        }

        $tagged = [];
        $withEvidence = [];
        foreach ( $samples as $sample ) {
            if (
                ! is_array($sample) ||
                ! is_string($sample['sample_ref'] ?? null) ||
                '' === $sample['sample_ref'] ||
                empty($sample['candidate_trace_refs'])
            ) {
                continue;
            }
            $tagged[] = $sample;
            if ( is_array($sample['browser'] ?? null) ) {
                $withEvidence[] = $sample;
            }
        }

        if ( [] === $tagged ) {
            return [
                'classification' => 'BROWSER_EVIDENCE_INSUFFICIENT',
                'reason'         => 'no_server_tagged_browser_refresh',
                'entry_visible_to_user_proven' => false,
            ];
        }

        if ( [] === $withEvidence ) {
            return [
                'classification' => 'BROWSER_REFRESH_NOT_OBSERVED',
                'reason'         => 'server_tagged_refresh_without_client_receipt',
                'entry_visible_to_user_proven' => false,
            ];
        }

        $traceRefs = [];
        $selected = $withEvidence[count($withEvidence) - 1];
        foreach ( $withEvidence as $sample ) {
            foreach ( $sample['candidate_trace_refs'] ?? [] as $traceRef ) {
                if ( is_string($traceRef) && '' !== $traceRef ) {
                    $traceRefs[$traceRef] = true;
                }
            }
            $uiSignal = $sample['browser']['ui_signal'] ?? 'none';
            if ( in_array($uiSignal, ['title_change', 'dom_mutation', 'both'], true) ) {
                $selected = $sample;
            }
        }

        if ( count($traceRefs) > 1 ) {
            return [
                'classification' => 'BROWSER_EVIDENCE_AMBIGUOUS',
                'reason'         => 'browser_refresh_links_multiple_candidate_traces',
                'trace_refs'     => array_slice(array_keys($traceRefs), 0, self::TRACE_LIMIT),
                'entry_visible_to_user_proven' => false,
            ];
        }

        $browser = is_array($selected['browser'] ?? null) ? $selected['browser'] : [];
        $uiSignal = is_string($browser['ui_signal'] ?? null) ? $browser['ui_signal'] : 'none';
        $classification = in_array($uiSignal, ['title_change', 'dom_mutation', 'both'], true)
            ? 'BROWSER_UI_SIGNAL_OBSERVED'
            : 'BROWSER_RESPONSE_RECEIVED';
        $reason = 'BROWSER_UI_SIGNAL_OBSERVED' === $classification
            ? 'correlated_response_and_ui_signal_observed'
            : 'correlated_response_received_without_ui_signal';

        return [
            'classification' => $classification,
            'reason'         => $reason,
            'trace_ref'      => 1 === count($traceRefs) ? array_key_first($traceRefs) : null,
            'sample_ref'     => $selected['sample_ref'] ?? null,
            'response_outcome' => $browser['outcome'] ?? 'unknown',
            'http_status'    => $browser['http_status'] ?? null,
            'ui_signal'      => $uiSignal,
            'visibility'     => $browser['visibility'] ?? 'unknown',
            'entry_visible_to_user_proven' => false,
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
            'sample_ref'           => $this->browserHeaderEmitted ? $this->browserSampleRef : null,
            'candidate_trace_refs' => array_slice(array_keys($this->inboxCandidateTraceRefs), 0, self::TRACE_LIMIT),
        ];
    }

    private function enqueueBrowserObserverForSession(array $session): void {
        if (
            'observing' !== ($session['data']['status'] ?? null) ||
            ! $this->browserEvidenceAuthorized() ||
            ! function_exists('wp_enqueue_script') ||
            ! function_exists('wp_add_inline_script') ||
            ! function_exists('wp_create_nonce') ||
            ! function_exists('admin_url')
        ) {
            return;
        }

        wp_enqueue_script(
            self::BROWSER_SCRIPT_HANDLE,
            WDDTF_URL . 'assets/gravity-browser-observer.js',
            ['jquery'],
            WDDTF_VERSION,
            true
        );

        $config = [
            'ajaxUrl'               => admin_url('admin-ajax.php'),
            'action'                => self::BROWSER_EVIDENCE_ACTION,
            'nonce'                 => wp_create_nonce($this->browserNonceAction($session['id'])),
            'headerName'            => self::BROWSER_SAMPLE_HEADER,
            'uiObservationWindowMs' => self::BROWSER_UI_WINDOW_MS,
        ];
        $json = wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ( is_string($json) ) {
            wp_add_inline_script(
                self::BROWSER_SCRIPT_HANDLE,
                'window.WDDTFGravityBrowserEvidence = ' . $json . ';',
                'before'
            );
        }
    }

    private function maybeTagBrowserResponse(string $sessionId): void {
        if (
            $this->browserHeaderEmitted ||
            'ajax' !== $this->requestTransport() ||
            ! $this->browserEvidenceAuthorized() ||
            [] === $this->inboxCandidateTraceRefs
        ) {
            return;
        }

        if ( null === $this->browserSampleRef ) {
            try {
                $this->browserSampleRef = 'gb-' . bin2hex(random_bytes(12));
            } catch (\Throwable) {
                return;
            }
        }

        $this->browserHeaderEmitted = true === ($this->headerEmitter)(
            self::BROWSER_SAMPLE_HEADER,
            $this->browserSampleRef
        );
    }

    private function browserEvidenceAuthorized(): bool {
        return function_exists('is_user_logged_in')
            && function_exists('current_user_can')
            && is_user_logged_in()
            && current_user_can(self::BROWSER_CAPABILITY);
    }

    private function browserNonceAction(string $sessionId): string {
        return self::BROWSER_NONCE_DOMAIN . '|' . $sessionId;
    }

    private function browserEvidencePayload(array $input): ?array {
        $allowedKeys = [
            'action',
            'nonce',
            'sample_ref',
            'outcome',
            'http_status',
            'client_received_ms',
            'visibility',
            'ui_signal',
            'duration_ms',
        ];
        foreach ( array_keys($input) as $key ) {
            if ( ! is_string($key) || ! in_array($key, $allowedKeys, true) ) {
                return null;
            }
        }

        $sampleRef = isset($input['sample_ref']) && is_string($input['sample_ref'])
            ? trim(wp_unslash($input['sample_ref']))
            : '';
        if ( 1 !== preg_match('/^gb-[a-f0-9]{24}$/', $sampleRef) ) {
            return null;
        }

        $outcome = isset($input['outcome']) && is_string($input['outcome'])
            ? sanitize_key(wp_unslash($input['outcome']))
            : '';
        if ( ! in_array($outcome, ['success', 'error'], true) ) {
            return null;
        }

        $httpStatus = $this->boundedInt($input['http_status'] ?? null, 0, 599);
        $clientReceivedMs = $this->boundedInt($input['client_received_ms'] ?? null, 1, 9999999999999);
        if ( null === $httpStatus || null === $clientReceivedMs ) {
            return null;
        }

        $visibility = isset($input['visibility']) && is_string($input['visibility'])
            ? sanitize_key(wp_unslash($input['visibility']))
            : 'unknown';
        if ( ! in_array($visibility, ['visible', 'hidden', 'prerender', 'unknown'], true) ) {
            return null;
        }

        $uiSignal = isset($input['ui_signal']) && is_string($input['ui_signal'])
            ? sanitize_key(wp_unslash($input['ui_signal']))
            : 'none';
        if ( ! in_array($uiSignal, ['none', 'title_change', 'dom_mutation', 'both'], true) ) {
            return null;
        }

        $duration = null;
        if ( array_key_exists('duration_ms', $input) && '' !== (string) $input['duration_ms'] ) {
            if ( ! is_numeric($input['duration_ms']) ) {
                return null;
            }
            $duration = round((float) $input['duration_ms'], 2);
            if ( $duration < 0 || $duration > 300000 ) {
                return null;
            }
        }

        $receivedSeconds = intdiv($clientReceivedMs, 1000);
        return [
            'sample_ref' => $sampleRef,
            'browser'    => [
                'client_received_timestamp_ms' => $clientReceivedMs,
                'client_received_at'           => gmdate('c', $receivedSeconds),
                'server_received_timestamp'    => ($this->clock)(),
                'server_received_at'           => gmdate('c', ($this->clock)()),
                'outcome'                      => $outcome,
                'http_status'                  => $httpStatus,
                'duration_ms'                  => $duration,
                'visibility'                   => $visibility,
                'ui_signal'                    => $uiSignal,
                'title_changed'                => in_array($uiSignal, ['title_change', 'both'], true),
                'dom_mutation_observed'        => in_array($uiSignal, ['dom_mutation', 'both'], true),
                'response_body_stored'         => false,
                'request_body_stored'          => false,
                'dom_content_stored'           => false,
            ],
        ];
    }

    private function boundedInt(mixed $value, int $min, int $max): ?int {
        if ( is_int($value) ) {
            $number = $value;
        } elseif ( is_string($value) && ctype_digit($value) ) {
            $number = (int) $value;
        } else {
            return null;
        }

        return $number >= $min && $number <= $max ? $number : null;
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

    private function traceRef(string $sessionId, int $entryId): ?string {
        return $this->opaqueRef($sessionId, 'entry', (string) $entryId, 'gt');
    }

    private function opaqueRef(string $sessionId, string $kind, string $value, ?string $prefix = null): ?string {
        $secret = $this->correlationSecret();
        if ( null === $secret ) {
            $this->sessions->markIntegrityUncertain($sessionId, 'pseudonym_secret_unavailable');
            return null;
        }

        $prefix ??= match ($kind) {
            'form' => 'gf',
            'step' => 'gs',
            'assignee' => 'ga',
            default => 'gx',
        };
        $message = self::REF_DOMAIN . '|' . $sessionId . '|' . $kind . '|' . $value;

        return $prefix . '-' . substr(hash_hmac('sha256', $message, $secret), 0, 16);
    }

    private function correlationSecret(): ?string {
        if ( ! function_exists('wp_salt') ) {
            return null;
        }

        $secret = wp_salt('auth');
        if ( ! is_string($secret) || strlen($secret) < 32 ) {
            return null;
        }

        return hash_hmac('sha256', 'wddtf-gravity-correlation-key:v1', $secret, true);
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
            'browser_evidence_count'  => 0,
            'truncated'               => false,
            'last_observed_timestamp' => null,
            'last_observed_at'        => null,
            'samples'                 => [],
            'trace_limit'             => self::TRACE_LIMIT,
            'trace_event_limit'       => self::TRACE_EVENT_LIMIT,
            'trace_count'             => 0,
            'traces_truncated'        => false,
            'traces'                  => [],
            'integrity'               => [
                'uncertain' => false,
                'reason'    => null,
                'marked_at' => null,
            ],
            'analysis'                => [
                'classification' => 'ENTRY_NOT_OBSERVED',
                'reason'         => 'no_candidate_entry_lifecycle_observed',
            ],
            'browser_analysis'        => [
                'classification' => 'BROWSER_EVIDENCE_INSUFFICIENT',
                'reason'         => 'no_server_tagged_browser_refresh',
                'entry_visible_to_user_proven' => false,
            ],
            'evidence'                => [
                'observer_hook'                    => self::INBOX_FILTER,
                'row_observer_hook'                => self::INBOX_FIELD_VALUE_FILTER,
                'inbox_render_observed'            => false,
                'ajax_inbox_render_observed'       => false,
                'causal_lifecycle_observer'        => true,
                'request_duration_measured'        => false,
                'browser_observer_available'       => true,
                'browser_response_received'        => false,
                'browser_ui_signal_observed'       => false,
                'raw_form_entry_values_stored'     => false,
                'raw_host_identifiers_stored'      => false,
                'raw_assignee_identity_stored'     => false,
                'request_response_payloads_stored' => false,
                'generic_ajax_activity_stored'     => false,
                'session_integrity_uncertain'      => false,
            ],
            'unknowns'                => [
                'client_round_trip_not_measured'             => true,
                'entry_visible_to_user_not_proven'           => true,
                'root_cause_not_inferred'                    => true,
                'expected_assignee_not_configured'           => true,
                'authentic_host_runtime_not_established'     => true,
                'authentic_live_refresh_not_established'     => true,
                'email_token_assignee_browser_not_qualified' => true,
                'session_integrity_uncertain'                => false,
            ],
        ];
    }
}
