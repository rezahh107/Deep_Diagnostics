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

    private const CURRENT_SESSION_KEY = 'wddtf_gravityflow_inbox_observation_current';
    private const SESSION_TYPE = 'gravityflow_inbox_observation';
    private const SESSION_TTL = 900;
    private const SAMPLE_LIMIT = 20;

    private SessionStore $sessions;
    private Closure $clock;
    private float $requestStartedAt;
    private ?string $observedSessionId = null;
    private int $inboxHookCalls = 0;

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
        add_filter(self::INBOX_FILTER, [$this, 'observeInboxRender'], PHP_INT_MAX, 2);
        add_action('shutdown', [$this, 'persistObservedInboxRequest'], 9998);
    }

    public function startInboxObservation(): array {
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

    public function observeInboxRender(mixed $columns, mixed $args = null): mixed {
        $session = $this->loadCurrentSession();
        if ( ! is_array($session) || 'observing' !== ($session['data']['status'] ?? null) ) {
            return $columns;
        }

        $this->observedSessionId = $session['id'];
        ++$this->inboxHookCalls;

        return $columns;
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
            'evidence'                => [
                'observer_hook'                => self::INBOX_FILTER,
                'inbox_render_observed'        => $total > 0,
                'ajax_inbox_render_observed'   => $ajaxSamples > 0,
                'request_duration_measured'    => $total > 0,
                'raw_form_entry_values_stored' => false,
            ],
            'unknowns'                => [
                'client_round_trip_not_measured' => true,
                'root_cause_not_inferred'        => true,
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

        $transport = 'frontend';
        if ( function_exists('wp_doing_ajax') && wp_doing_ajax() ) {
            $transport = 'ajax';
        } elseif ( defined('REST_REQUEST') && REST_REQUEST ) {
            $transport = 'rest';
        } elseif ( defined('DOING_CRON') && DOING_CRON ) {
            $transport = 'cron';
        } elseif ( defined('WP_CLI') && WP_CLI ) {
            $transport = 'wp_cli';
        } elseif ( function_exists('is_admin') && is_admin() ) {
            $transport = 'admin';
        }

        $queryCount = null;
        if ( isset($wpdb) && is_object($wpdb) && isset($wpdb->num_queries) && is_numeric($wpdb->num_queries) ) {
            $queryCount = (int) $wpdb->num_queries;
        }

        return [
            'observed_timestamp' => $now,
            'observed_at'        => gmdate('c', $now),
            'transport'          => $transport,
            'elapsed_ms'         => round(max(0.0, (microtime(true) - $this->requestStartedAt) * 1000), 2),
            'memory_peak_bytes'  => memory_get_peak_usage(true),
            'db_query_count'     => $queryCount,
            'observer_hook'      => self::INBOX_FILTER,
            'hook_calls'         => $this->inboxHookCalls,
        ];
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
            'evidence'                => [
                'observer_hook'                => self::INBOX_FILTER,
                'inbox_render_observed'        => false,
                'ajax_inbox_render_observed'   => false,
                'request_duration_measured'    => false,
                'raw_form_entry_values_stored' => false,
            ],
            'unknowns'                => [
                'client_round_trip_not_measured' => true,
                'root_cause_not_inferred'        => true,
            ],
        ];
    }
}
