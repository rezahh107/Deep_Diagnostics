<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WDDTF\Cron\CronDiagnostics;
use WDDTF\Diagnostics\SessionStore;

final class CronDiagnosticsTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_transients'] = [];
        $GLOBALS['wddtf_test_transient_failures'] = [];
        $GLOBALS['wddtf_test_cron_events'] = [];
        $GLOBALS['wddtf_test_ready_cron_jobs'] = [];
        $GLOBALS['wddtf_test_schedule_calls'] = [];
        $GLOBALS['wddtf_test_schedule_result'] = true;
        $GLOBALS['wddtf_test_hide_scheduled_events'] = false;
    }

    public function test_start_schedules_exactly_one_probe_and_prevents_duplicate_pending_probe(): void {
        $now = 1000;
        $cron = $this->makeCron($now, 'ds-1111111111111111');

        $started = $cron->startQualification();
        self::assertTrue($started['started']);
        self::assertSame('scheduled', $started['reason']);
        self::assertCount(1, $GLOBALS['wddtf_test_schedule_calls']);
        self::assertSame(CronDiagnostics::PROBE_HOOK, $GLOBALS['wddtf_test_schedule_calls'][0][1]);
        self::assertSame([$started['qualification']['session_id']], $GLOBALS['wddtf_test_schedule_calls'][0][2]);
        self::assertSame('pending', $started['qualification']['status']);
        self::assertTrue($started['qualification']['scheduled']);
        self::assertTrue($started['qualification']['scheduled_event_present']);

        $duplicate = $cron->startQualification();
        self::assertFalse($duplicate['started']);
        self::assertSame('already_pending', $duplicate['reason']);
        self::assertCount(1, $GLOBALS['wddtf_test_schedule_calls']);
    }

    public function test_successful_schedule_without_observable_event_remains_unknown_not_failed(): void {
        $now = 1000;
        $GLOBALS['wddtf_test_hide_scheduled_events'] = true;
        $cron = $this->makeCron($now, 'ds-1212121212121212');

        $result = $cron->startQualification();

        self::assertFalse($result['started']);
        self::assertSame('scheduled_event_not_observable', $result['reason']);
        self::assertSame('unknown', $result['qualification']['status']);
        self::assertSame('pending_event_not_observable', $result['qualification']['reason']);
        self::assertTrue($result['qualification']['scheduled']);
        self::assertFalse($result['qualification']['evidence']['execution_observed']);
        self::assertTrue($result['qualification']['unknowns']['pending_event_missing']);
    }

    public function test_probe_observation_records_correlated_timestamp_and_measured_delay(): void {
        $now = 1000;
        $cron = $this->makeCron($now, 'ds-2222222222222222');
        $started = $cron->startQualification();
        $sessionId = $started['qualification']['session_id'];

        $now = 1017;
        $cron->observeProbe($sessionId);
        $qualification = $cron->currentQualification();

        self::assertSame('completed', $qualification['status']);
        self::assertSame($sessionId, $qualification['session_id']);
        self::assertSame(1000, $qualification['expected_timestamp']);
        self::assertSame(1017, $qualification['observed_timestamp']);
        self::assertSame(17, $qualification['delay_seconds']);
        self::assertTrue($qualification['evidence']['execution_observed']);
        self::assertTrue($qualification['evidence']['delay_measured']);
    }

    public function test_ready_event_observation_keeps_timing_and_recurrence_but_drops_arguments(): void {
        $now = 1000;
        $canary = 'SecretCronArgumentCanary123456789012345';
        $GLOBALS['wddtf_test_ready_cron_jobs'] = [
            970 => [
                'example_hook' => [
                    'signature' => [
                        'schedule' => 'hourly',
                        'args' => [$canary],
                        'interval' => 3600,
                    ],
                ],
            ],
        ];

        $cron = $this->makeCron($now, 'ds-3333333333333333');
        $snapshot = $cron->snapshot();
        $encoded = (string) wp_json_encode($snapshot);
        $event = $snapshot['ready_events']['events'][0];

        self::assertSame(1, $snapshot['ready_events']['count']);
        self::assertSame('example_hook', $event['hook']);
        self::assertSame(30, $event['delay_seconds']);
        self::assertSame('hourly', $event['schedule']);
        self::assertSame(3600, $event['interval_seconds']);
        self::assertArrayNotHasKey('args', $event);
        self::assertStringNotContainsString($canary, $encoded);
    }

    public function test_passive_snapshot_never_schedules_or_mutates_state(): void {
        $now = 1000;
        $cron = $this->makeCron($now, 'ds-4444444444444444');
        $before = $GLOBALS['wddtf_test_transients'];

        $snapshot = $cron->snapshot();

        self::assertSame('not_started', $snapshot['qualification']['status']);
        self::assertSame([], $GLOBALS['wddtf_test_schedule_calls']);
        self::assertSame($before, $GLOBALS['wddtf_test_transients']);
    }

    public function test_expired_session_reads_remain_unknown_without_mutation_and_explicit_start_recovers(): void {
        $now = 1000;
        $cron = $this->makeCron($now, 'ds-5555555555555555');
        $cron->startQualification();

        $now = 1000 + 43201;
        $before = $GLOBALS['wddtf_test_transients'];
        $first = $cron->currentQualification();
        $second = $cron->currentQualification();

        self::assertSame('unknown', $first['status']);
        self::assertSame('expired_or_invalid_session', $first['reason']);
        self::assertSame($first, $second);
        self::assertSame($before, $GLOBALS['wddtf_test_transients']);

        $GLOBALS['wddtf_test_cron_events'] = [];
        $GLOBALS['wddtf_test_schedule_calls'] = [];
        $restarted = $cron->startQualification();
        self::assertTrue($restarted['started']);
        self::assertSame('scheduled', $restarted['reason']);
        self::assertCount(1, $GLOBALS['wddtf_test_schedule_calls']);
    }

    public function test_schedule_failure_is_reported_without_success_claim(): void {
        $now = 1000;
        $GLOBALS['wddtf_test_schedule_result'] = new WP_Error('blocked_by_test');
        $cron = $this->makeCron($now, 'ds-6666666666666666');

        $result = $cron->startQualification();

        self::assertFalse($result['started']);
        self::assertSame('schedule_failed', $result['reason']);
        self::assertSame('error', $result['qualification']['status']);
        self::assertSame('wp_error:blocked_by_test', $result['qualification']['reason']);
        self::assertFalse($result['qualification']['evidence']['execution_observed']);
    }

    #[RunInSeparateProcess]
    public function test_disabled_wp_cron_configuration_is_reported_truthfully(): void {
        define('DISABLE_WP_CRON', true);
        $now = 1000;
        $cron = $this->makeCron($now, 'ds-7777777777777777');

        $configuration = $cron->snapshot()['configuration'];

        self::assertTrue($configuration['wp_cron_disabled']);
        self::assertFalse($configuration['automatic_wp_cron_enabled']);
        self::assertSame('disabled_by_constant', $configuration['mode']);
    }

    #[RunInSeparateProcess]
    public function test_alternate_wp_cron_configuration_is_reported_truthfully(): void {
        define('ALTERNATE_WP_CRON', true);
        $now = 1000;
        $cron = $this->makeCron($now, 'ds-7878787878787878');

        $configuration = $cron->snapshot()['configuration'];

        self::assertTrue($configuration['alternate_wp_cron']);
        self::assertTrue($configuration['automatic_wp_cron_enabled']);
        self::assertSame('alternate', $configuration['mode']);
    }

    private function makeCron(int &$now, string $id): CronDiagnostics {
        $clock = static function() use (&$now): int { return $now; };
        $store = new SessionStore($clock, static fn(): string => $id);

        return new CronDiagnostics($store, $clock);
    }
}
