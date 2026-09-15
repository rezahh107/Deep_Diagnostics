<?php
declare(strict_types=1);

namespace WDDTF\Cron;

use Closure;
use WDDTF\Diagnostics\SessionStore;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class CronDiagnostics {
    public const PROBE_HOOK = 'wddtf_cron_qualification_probe';

    private const CURRENT_SESSION_KEY = 'wddtf_cron_qualification_current';
    private const SESSION_TYPE = 'wp_cron_qualification';
    private const SESSION_TTL = 43200;
    private const READY_EVENT_LIMIT = 50;

    private SessionStore $sessions;
    private Closure $clock;

    public function __construct(?SessionStore $sessions = null, ?callable $clock = null) {
        $this->clock    = Closure::fromCallable($clock ?? static fn(): int => time());
        $this->sessions = $sessions ?? new SessionStore($this->clock);
    }

    public function register(): void {
        add_action(self::PROBE_HOOK, [$this, 'observeProbe'], 10, 1);
    }

    public function startQualification(): array {
        $currentId = get_transient(self::CURRENT_SESSION_KEY);

        if ( is_string($currentId) && '' !== $currentId ) {
            $current = $this->sessions->load($currentId);
            if ( is_array($current) ) {
                $currentQualification = $this->qualificationFromSession($current);
                if (
                    'pending' === ($current['data']['status'] ?? null) &&
                    'pending' === ($currentQualification['status'] ?? null)
                ) {
                    return [
                        'started'       => false,
                        'reason'        => 'already_pending',
                        'qualification' => $currentQualification,
                    ];
                }
            }

            // Cleanup is an explicit side effect of the operator-triggered action only.
            $this->sessions->delete($currentId);
            delete_transient(self::CURRENT_SESSION_KEY);
        } elseif ( false !== $currentId ) {
            // Malformed pointers are left untouched by passive reads and repaired only here.
            delete_transient(self::CURRENT_SESSION_KEY);
        }

        $now = ($this->clock)();
        $session = $this->sessions->create(
            self::SESSION_TYPE,
            [
                'status'             => 'pending',
                'scheduled'          => false,
                'expected_timestamp' => $now,
                'observed_timestamp' => null,
                'execution_context'  => [],
                'schedule_error'     => null,
            ],
            self::SESSION_TTL
        );

        if ( null === $session ) {
            return [
                'started'       => false,
                'reason'        => 'session_persistence_failed',
                'qualification' => $this->emptyQualification('error'),
            ];
        }

        if ( ! set_transient(self::CURRENT_SESSION_KEY, $session['id'], self::SESSION_TTL) ) {
            $this->sessions->delete($session['id']);
            return [
                'started'       => false,
                'reason'        => 'current_session_persistence_failed',
                'qualification' => $this->emptyQualification('error'),
            ];
        }

        $scheduled = wp_schedule_single_event(
            $now,
            self::PROBE_HOOK,
            [$session['id']],
            true
        );

        if ( is_wp_error($scheduled) || true !== $scheduled ) {
            $this->markScheduleError(
                $session,
                is_wp_error($scheduled) ? 'wp_error:' . (string) $scheduled->get_error_code() : 'schedule_failed'
            );

            return [
                'started'       => false,
                'reason'        => 'schedule_failed',
                'qualification' => $this->currentQualification(),
            ];
        }

        $latest = $this->sessions->load($session['id']) ?? $session;
        $latest['data']['scheduled'] = true;
        $this->sessions->save($latest);

        $event = wp_get_scheduled_event(self::PROBE_HOOK, [$session['id']], $now);
        if ( false === $event ) {
            $latest = $this->sessions->load($session['id']);
            if ( ! is_array($latest) || 'completed' !== ($latest['data']['status'] ?? null) ) {
                // Scheduling succeeded, but absence on immediate reread is ambiguous: the
                // event may already have been claimed/executed. Do not invent failure.
                return [
                    'started'       => false,
                    'reason'        => 'scheduled_event_not_observable',
                    'qualification' => $this->currentQualification(),
                ];
            }
        }

        return [
            'started'       => true,
            'reason'        => 'scheduled',
            'qualification' => $this->currentQualification(),
        ];
    }

    public function observeProbe(string $sessionId): void {
        $session = $this->sessions->load($sessionId);
        if ( ! is_array($session) || self::SESSION_TYPE !== $session['type'] ) {
            return;
        }

        if ( 'pending' !== ($session['data']['status'] ?? null) ) {
            return;
        }

        $now = ($this->clock)();
        $expected = (int) ($session['data']['expected_timestamp'] ?? $now);

        $session['data']['status']             = 'completed';
        $session['data']['scheduled']          = true;
        $session['data']['observed_timestamp'] = $now;
        $session['data']['delay_seconds']      = max(0, $now - $expected);
        $session['data']['execution_context']  = [
            'doing_cron' => defined('DOING_CRON') && DOING_CRON,
            'wp_cli'     => defined('WP_CLI') && WP_CLI,
        ];

        $this->sessions->save($session);
    }

    public function snapshot(): array {
        $now = ($this->clock)();

        return [
            'configuration' => $this->configuration(),
            'ready_events'  => $this->readyEvents($now),
            'qualification' => $this->currentQualification($now),
        ];
    }

    public function currentQualification(?int $now = null): array {
        $now ??= ($this->clock)();
        $currentId = get_transient(self::CURRENT_SESSION_KEY);

        if ( false === $currentId ) {
            return $this->emptyQualification('not_started');
        }

        if ( ! is_string($currentId) || '' === $currentId ) {
            return $this->emptyQualification('unknown', 'invalid_current_session_pointer');
        }

        $session = $this->sessions->load($currentId);
        if ( ! is_array($session) ) {
            return $this->emptyQualification('unknown', 'expired_or_invalid_session');
        }

        if ( self::SESSION_TYPE !== $session['type'] ) {
            return $this->emptyQualification('unknown', 'unexpected_session_type');
        }

        return $this->qualificationFromSession($session, $now);
    }

    private function qualificationFromSession(array $session, ?int $now = null): array {
        $now ??= ($this->clock)();
        $data = $session['data'];
        $storedStatus = is_string($data['status'] ?? null) ? $data['status'] : 'unknown';
        $expected = is_int($data['expected_timestamp'] ?? null) ? $data['expected_timestamp'] : null;
        $observed = is_int($data['observed_timestamp'] ?? null) ? $data['observed_timestamp'] : null;
        $scheduled = true === ($data['scheduled'] ?? false);
        $eventPresent = false;

        if ( $scheduled && null !== $expected && 'pending' === $storedStatus ) {
            $eventPresent = false !== wp_get_scheduled_event(self::PROBE_HOOK, [$session['id']], $expected);
        }

        $status = in_array($storedStatus, ['pending', 'completed', 'error'], true) ? $storedStatus : 'unknown';
        $reason = is_string($data['schedule_error'] ?? null) ? $data['schedule_error'] : null;

        if ( 'pending' === $status && $scheduled && ! $eventPresent ) {
            $status = 'unknown';
            $reason = 'pending_event_not_observable';
        }

        $executionObserved = 'completed' === $storedStatus && null !== $observed;
        $delay = $executionObserved && null !== $expected ? max(0, $observed - $expected) : null;
        $due = null !== $expected && $expected <= $now && ! $executionObserved;
        $context = is_array($data['execution_context'] ?? null) ? $data['execution_context'] : [];

        $trigger = 'unknown';
        if ( true === ($context['wp_cli'] ?? false) ) {
            $trigger = 'wp_cli';
        } elseif ( true === ($context['doing_cron'] ?? false) ) {
            $trigger = 'wp_cron';
        }

        return [
            'status'                  => $status,
            'reason'                  => $reason,
            'session_id'              => $session['id'],
            'scheduled'               => $scheduled,
            'scheduled_event_present' => $eventPresent,
            'expected_timestamp'      => $expected,
            'expected_at'             => null !== $expected ? gmdate('c', $expected) : null,
            'observed_timestamp'      => $observed,
            'observed_at'             => null !== $observed ? gmdate('c', $observed) : null,
            'delay_seconds'           => $delay,
            'due_not_observed'        => $due,
            'attention'               => $due || in_array($status, ['error', 'unknown'], true),
            'execution_context'       => [
                'doing_cron' => true === ($context['doing_cron'] ?? false),
                'wp_cli'     => true === ($context['wp_cli'] ?? false),
                'trigger'    => $trigger,
            ],
            'evidence'                => [
                'schedule_recorded'           => $scheduled,
                'scheduled_event_present_now' => $eventPresent,
                'execution_observed'          => $executionObserved,
                'delay_measured'              => null !== $delay,
            ],
            'unknowns'                => [
                'execution_not_observed'       => ! $executionObserved,
                'trigger_mechanism_not_proven' => 'unknown' === $trigger,
                'pending_event_missing'        => 'pending_event_not_observable' === $reason,
                'root_cause_not_inferred'      => true,
            ],
        ];
    }

    private function configuration(): array {
        $disabled  = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $alternate = defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON;

        $mode = 'standard';
        if ( $disabled ) {
            $mode = 'disabled_by_constant';
        } elseif ( $alternate ) {
            $mode = 'alternate';
        }

        return [
            'automatic_wp_cron_enabled' => ! $disabled,
            'wp_cron_disabled'           => $disabled,
            'alternate_wp_cron'          => $alternate,
            'mode'                       => $mode,
        ];
    }

    private function readyEvents(int $now): array {
        if ( ! function_exists('wp_get_ready_cron_jobs') ) {
            return [
                'api_available'      => false,
                'observed_timestamp' => $now,
                'observed_at'        => gmdate('c', $now),
                'count'              => 0,
                'truncated'          => false,
                'events'             => [],
            ];
        }

        $ready  = wp_get_ready_cron_jobs();
        $events = [];
        $count  = 0;

        foreach ( $ready as $timestamp => $hooks ) {
            foreach ( (array) $hooks as $hook => $instances ) {
                foreach ( (array) $instances as $instance ) {
                    ++$count;
                    if ( count($events) >= self::READY_EVENT_LIMIT ) {
                        continue;
                    }

                    $scheduledAt = (int) $timestamp;
                    $schedule = is_string($instance['schedule'] ?? null) && '' !== $instance['schedule']
                        ? $instance['schedule']
                        : null;
                    $interval = isset($instance['interval']) && is_numeric($instance['interval'])
                        ? (int) $instance['interval']
                        : null;

                    $events[] = [
                        'hook'                => (string) $hook,
                        'scheduled_timestamp' => $scheduledAt,
                        'scheduled_at'        => gmdate('c', $scheduledAt),
                        'observed_timestamp'  => $now,
                        'observed_at'         => gmdate('c', $now),
                        'delay_seconds'       => max(0, $now - $scheduledAt),
                        'schedule'            => $schedule,
                        'interval_seconds'    => $interval,
                        'due_waiting'         => true,
                    ];
                }
            }
        }

        return [
            'api_available'      => true,
            'observed_timestamp' => $now,
            'observed_at'        => gmdate('c', $now),
            'count'              => $count,
            'truncated'          => $count > count($events),
            'events'             => $events,
        ];
    }

    private function markScheduleError(array $session, string $code): void {
        $session['data']['status']         = 'error';
        $session['data']['schedule_error'] = $code;
        $this->sessions->save($session);
    }

    private function emptyQualification(string $status, ?string $reason = null): array {
        return [
            'status'                  => $status,
            'reason'                  => $reason,
            'session_id'              => null,
            'scheduled'               => false,
            'scheduled_event_present' => false,
            'expected_timestamp'      => null,
            'expected_at'             => null,
            'observed_timestamp'      => null,
            'observed_at'             => null,
            'delay_seconds'           => null,
            'due_not_observed'        => false,
            'attention'               => in_array($status, ['error', 'unknown'], true),
            'execution_context'       => [
                'doing_cron' => false,
                'wp_cli'     => false,
                'trigger'    => 'unknown',
            ],
            'evidence'                => [
                'schedule_recorded'           => false,
                'scheduled_event_present_now' => false,
                'execution_observed'          => false,
                'delay_measured'              => false,
            ],
            'unknowns'                => [
                'execution_not_observed'       => true,
                'trigger_mechanism_not_proven' => true,
                'pending_event_missing'        => false,
                'root_cause_not_inferred'      => true,
            ],
        ];
    }
}
