<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\SessionStore;
use WDDTF\Gravity\GravityDiagnostics;

if ( ! class_exists('GFForms') ) {
    final class GFForms {
        public static string $version = '3.1.1.1';
    }
}

if ( ! class_exists('Gravity_Flow') ) {
    final class Gravity_Flow {
    }
}

final class GravityDiagnosticsTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_actions'] = [];
        $GLOBALS['wddtf_test_filters'] = [];
        $GLOBALS['wddtf_test_transients'] = [];
        $GLOBALS['wddtf_test_transient_failures'] = [];
        $GLOBALS['wddtf_test_is_ajax'] = false;
        $GLOBALS['wddtf_test_is_admin'] = false;
        $GLOBALS['wddtf_test_did_actions'] = [];
    }

    public function test_register_uses_documented_inbox_filter_and_shutdown_persistence(): void {
        $diagnostics = $this->makeDiagnostics();

        $diagnostics->register();

        self::assertArrayHasKey(GravityDiagnostics::INBOX_FILTER, $GLOBALS['wddtf_test_filters']);
        self::assertSame(PHP_INT_MAX, $GLOBALS['wddtf_test_filters'][GravityDiagnostics::INBOX_FILTER][0][1]);
        self::assertSame(2, $GLOBALS['wddtf_test_filters'][GravityDiagnostics::INBOX_FILTER][0][2]);
        self::assertArrayHasKey('shutdown', $GLOBALS['wddtf_test_actions']);
        self::assertSame(9998, $GLOBALS['wddtf_test_actions']['shutdown'][0][1]);
    }

    public function test_start_is_bounded_and_duplicate_observation_is_not_created(): void {
        $diagnostics = $this->makeDiagnostics();

        $started = $diagnostics->startInboxObservation();
        $duplicate = $diagnostics->startInboxObservation();

        self::assertTrue($started['started']);
        self::assertSame('observing', $started['reason']);
        self::assertSame('observing', $started['observation']['status']);
        self::assertSame(20, $started['observation']['sample_limit']);
        self::assertLessThanOrEqual(900, $started['observation']['remaining_seconds']);
        self::assertFalse($duplicate['started']);
        self::assertSame('already_observing', $duplicate['reason']);
        self::assertSame($started['observation']['session_id'], $duplicate['observation']['session_id']);
    }

    public function test_ajax_inbox_render_persists_only_bounded_metadata_and_not_host_values(): void {
        $diagnostics = $this->makeDiagnostics();
        $diagnostics->startInboxObservation();
        $GLOBALS['wddtf_test_is_ajax'] = true;
        $canary = 'SecretEntryValueCanary123456789012345';
        $columns = ['entry' => 'Entry'];

        $returned = $diagnostics->observeInboxRender($columns, ['entry' => $canary, 'form_id' => 91]);
        $diagnostics->persistObservedInboxRequest();
        $snapshot = $diagnostics->snapshot();
        $observation = $snapshot['inbox_observation'];
        $sample = $observation['samples'][0];
        $encoded = (string) wp_json_encode($snapshot);

        self::assertSame($columns, $returned);
        self::assertSame(1, $observation['sample_count_total']);
        self::assertSame(1, $observation['ajax_sample_count']);
        self::assertSame('ajax', $sample['transport']);
        self::assertSame(GravityDiagnostics::INBOX_FILTER, $sample['observer_hook']);
        self::assertGreaterThanOrEqual(0, $sample['elapsed_ms']);
        self::assertGreaterThan(0, $sample['memory_peak_bytes']);
        self::assertStringNotContainsString($canary, $encoded);
        self::assertStringNotContainsString('form_id', $encoded);
        self::assertStringNotContainsString('entry', strtolower($encoded));
        self::assertFalse($observation['evidence']['raw_form_entry_values_stored']);
    }

    public function test_passive_snapshot_does_not_create_or_repair_state(): void {
        set_transient('wddtf_gravityflow_inbox_observation_current', 'malformed-pointer', 900);
        $before = $GLOBALS['wddtf_test_transients'];
        $diagnostics = $this->makeDiagnostics();

        $first = $diagnostics->snapshot()['inbox_observation'];
        $second = $diagnostics->snapshot()['inbox_observation'];

        self::assertSame('unknown', $first['status']);
        self::assertSame('expired_or_invalid_session', $first['reason']);
        self::assertSame($first, $second);
        self::assertSame($before, $GLOBALS['wddtf_test_transients']);
    }

    public function test_observation_stops_writing_after_twenty_samples(): void {
        $now = 1000;
        $store = new SessionStore(
            static function() use (&$now): int { return $now; },
            static fn(): string => 'ds-aaaaaaaaaaaaaaaa'
        );
        $first = new GravityDiagnostics($store, static function() use (&$now): int { return $now; }, 999.0);
        $first->startInboxObservation();

        for ( $i = 0; $i < 20; ++$i ) {
            $now = 1001 + $i;
            $request = new GravityDiagnostics($store, static function() use (&$now): int { return $now; }, microtime(true));
            $request->observeInboxRender([], []);
            $request->persistObservedInboxRequest();
        }

        $complete = (new GravityDiagnostics($store, static function() use (&$now): int { return $now; }))->snapshot()['inbox_observation'];
        self::assertSame('completed', $complete['status']);
        self::assertSame(20, $complete['sample_count_total']);
        self::assertCount(20, $complete['samples']);

        ++$now;
        $extra = new GravityDiagnostics($store, static function() use (&$now): int { return $now; });
        $extra->observeInboxRender([], []);
        $extra->persistObservedInboxRequest();
        $after = $extra->snapshot()['inbox_observation'];

        self::assertSame(20, $after['sample_count_total']);
        self::assertCount(20, $after['samples']);
    }

    public function test_host_snapshot_reports_gravity_forms_version_without_collecting_business_data(): void {
        $hosts = $this->makeDiagnostics()->snapshot()['hosts'];

        self::assertTrue($hosts['gravity_forms']['available']);
        self::assertSame('3.1.1.1', $hosts['gravity_forms']['version']);
        self::assertTrue($hosts['gravity_flow']['available']);
    }

    private function makeDiagnostics(): GravityDiagnostics {
        $now = 1000;
        $clock = static function() use (&$now): int { return $now; };
        $store = new SessionStore($clock, static fn(): string => 'ds-bbbbbbbbbbbbbbbb');

        return new GravityDiagnostics($store, $clock, microtime(true));
    }
}
