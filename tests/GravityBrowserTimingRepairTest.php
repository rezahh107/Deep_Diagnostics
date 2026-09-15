<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\SessionStore;
use WDDTF\Gravity\GravityDiagnostics;

final class GravityBrowserTimingRepairTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_transients'] = [];
        $GLOBALS['wddtf_test_transient_failures'] = [];
        $GLOBALS['wddtf_test_options'] = [];
        $GLOBALS['wddtf_test_did_actions'] = ['gravityflow_loaded' => 1];
        $GLOBALS['wpdb'] = new wpdb();
    }

    protected function tearDown(): void {
        unset($GLOBALS['wpdb']);
    }

    public function test_receipt_only_browser_evidence_keeps_round_trip_unknown_and_omits_duration(): void {
        $observation = $this->observationWithBrowserEvidence(null);

        self::assertSame('BROWSER_RESPONSE_RECEIVED', $observation['browser_analysis']['classification']);
        self::assertTrue($observation['unknowns']['client_round_trip_not_measured']);
        self::assertArrayNotHasKey('duration_ms', $observation['samples'][0]['browser']);
        self::assertFalse($observation['browser_analysis']['entry_visible_to_user_proven']);
    }

    public function test_valid_browser_duration_clears_only_round_trip_uncertainty(): void {
        $observation = $this->observationWithBrowserEvidence(312.5);

        self::assertSame('BROWSER_RESPONSE_RECEIVED', $observation['browser_analysis']['classification']);
        self::assertFalse($observation['unknowns']['client_round_trip_not_measured']);
        self::assertSame(312.5, $observation['samples'][0]['browser']['duration_ms']);
        self::assertFalse($observation['browser_analysis']['entry_visible_to_user_proven']);
    }

    public function test_client_source_uses_prefilter_completion_before_consumer_callbacks_and_keeps_send_for_timing_only(): void {
        $source = (string) file_get_contents(dirname(__DIR__) . '/assets/gravity-browser-observer.js');

        self::assertStringContainsString('startedAt: performanceNow()', $source);
        self::assertStringContainsString('$.ajaxPrefilter(function (_options, _originalOptions, jqXHR)', $source);
        self::assertStringContainsString('jqXHR.always(function () {', $source);
        self::assertStringContainsString('observeCompletion(jqXHR);', $source);
        self::assertStringContainsString("if (typeof window.fetch === 'function')", $source);
        self::assertStringContainsString('var nativeFetch = window.fetch.bind(window);', $source);
        self::assertStringContainsString('window.fetch = function () {', $source);
        self::assertStringContainsString('response.headers.get(config.headerName)', $source);
        self::assertStringContainsString('scheduleTaggedEvidence(', $source);
        self::assertStringNotContainsString('ajaxComplete.wddtfGravityBrowserEvidence', $source);
        self::assertSame(1, substr_count($source, 'Date.now()'));

        $prefilter = strpos($source, '$.ajaxPrefilter(function');
        $sendStart = strpos($source, "ajaxSend.wddtfGravityBrowserEvidence");
        self::assertIsInt($prefilter);
        self::assertIsInt($sendStart);
        self::assertLessThan($sendStart, $prefilter);

        $scheduleStart = strpos($source, 'function scheduleTaggedEvidence(');
        $completionStart = strpos($source, 'function observeCompletion(xhr)');
        self::assertIsInt($scheduleStart);
        self::assertIsInt($completionStart);
        $scheduleBlock = substr($source, $scheduleStart, $completionStart - $scheduleStart);
        $completionBlock = substr($source, $completionStart, $prefilter - $completionStart);
        self::assertStringContainsString('xhr.getResponseHeader(config.headerName)', $completionBlock);
        self::assertStringContainsString('var clientReceivedMs = Date.now();', $scheduleBlock);
        self::assertStringContainsString('var responseBaseline = {', $scheduleBlock);
        self::assertStringContainsString('window.setTimeout(function ()', $scheduleBlock);

        $fetchStart = strpos($source, "if (typeof window.fetch === 'function')");
        self::assertIsInt($fetchStart);
        $fetchBlock = substr($source, $fetchStart, $prefilter - $fetchStart);
        self::assertStringContainsString('return nativeFetch.apply(window, arguments).then(function (response)', $fetchBlock);
        self::assertStringContainsString('response.headers.get(config.headerName)', $fetchBlock);
        self::assertStringContainsString('scheduleTaggedEvidence(', $fetchBlock);
        self::assertStringNotContainsString('.url', $fetchBlock);
        self::assertStringNotContainsString('response.text', $fetchBlock);
        self::assertStringNotContainsString('response.json', $fetchBlock);

        $sendBlock = substr($source, $sendStart);
        self::assertStringNotContainsString('mutationSequence:', $sendBlock);
        self::assertStringNotContainsString('title:', $sendBlock);
        self::assertStringNotContainsString('getResponseHeader', $sendBlock);

        $receipt = strpos($scheduleBlock, 'var clientReceivedMs = Date.now();');
        $baseline = strpos($scheduleBlock, 'var responseBaseline = {');
        $delay = strpos($scheduleBlock, 'window.setTimeout(function ()');
        self::assertIsInt($receipt);
        self::assertIsInt($baseline);
        self::assertIsInt($delay);
        self::assertLessThan($baseline, $receipt);
        self::assertLessThan($delay, $baseline);
        self::assertStringContainsString('client_received_ms: clientReceivedMs', $scheduleBlock);
        self::assertStringContainsString('ui_signal: classifyUiSignal(responseBaseline)', $scheduleBlock);
    }

    public function test_cron_truncation_markup_has_no_unmatched_wrapper_close(): void {
        $template = (string) file_get_contents(dirname(__DIR__) . '/templates/admin-page.php');
        $description = "The displayed event list is bounded and truncated; the total count above includes all ready event instances observed.";

        self::assertStringContainsString($description, $template);
        self::assertStringNotContainsString($description . "', 'wp-deep-diagnostics'); ?></p></div>", $template);
        self::assertStringContainsString($description . "', 'wp-deep-diagnostics'); ?></p>", $template);
    }

    private function observationWithBrowserEvidence(?float $duration): array {
        $now = 1000;
        $store = new SessionStore(
            static function() use (&$now): int { return $now; },
            static fn(): string => 'ds-bbbbbbbbbbbbbbbb',
            static fn(): string => 'lk-bbbbbbbbbbbbbbbbbbbbbbbb',
            static function(int $microseconds): void {}
        );

        $browser = [
            'client_received_timestamp_ms' => 1000123,
            'client_received_at' => '1970-01-01T00:16:40+00:00',
            'server_received_timestamp' => 1000,
            'server_received_at' => '1970-01-01T00:16:40+00:00',
            'outcome' => 'success',
            'http_status' => 200,
            'visibility' => 'visible',
            'ui_signal' => 'none',
            'title_changed' => false,
            'dom_mutation_observed' => false,
            'response_body_stored' => false,
            'request_body_stored' => false,
            'dom_content_stored' => false,
        ];
        if ( null !== $duration ) {
            $browser['duration_ms'] = $duration;
        }

        $session = $store->create(
            'gravityflow_inbox_observation',
            [
                'status' => 'observing',
                'samples' => [[
                    'observed_timestamp' => 1000,
                    'observed_at' => '1970-01-01T00:16:40+00:00',
                    'transport' => 'ajax',
                    'elapsed_ms' => 10.0,
                    'sample_ref' => 'gb-0123456789abcdefabcd',
                    'candidate_trace_refs' => ['gt-0123456789abcdef'],
                    'browser' => $browser,
                ]],
                'sample_count_total' => 1,
                'last_observed_timestamp' => 1000,
                'traces' => [],
                'trace_order' => [],
                'traces_truncated' => false,
            ],
            900
        );
        self::assertIsArray($session);
        set_transient('wddtf_gravityflow_inbox_observation_current', $session['id'], 900);

        $diagnostics = new GravityDiagnostics(
            $store,
            static function() use (&$now): int { return $now; },
            999.5,
            static fn(string $name, string $value): bool => true
        );

        return $diagnostics->currentInboxObservation();
    }
}
