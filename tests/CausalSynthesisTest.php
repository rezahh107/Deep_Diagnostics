<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\DiagnosticsAnalyzer;

final class CausalSynthesisTest extends TestCase {
    public function test_measured_blocking_http_is_a_strong_latency_signal(): void {
        $report = (new DiagnosticsAnalyzer())->analyze($this->snapshot([
            'http_requests' => [[
                'url'      => 'https://api.example.test/work',
                'duration' => 0.45,
                'blocking' => true,
                'result'   => ['response'],
                'args'     => ['method' => 'GET'],
            ]],
        ]));

        self::assertSame('strong_signal', $report['synthesis']['status']);
        $finding = $this->finding($report, 'blocking_http_latency');
        self::assertSame('strong_signal', $finding['strength']);
        self::assertSame(450.0, $finding['evidence']['blocking_ms']);
        self::assertSame(0.45, $finding['evidence']['request_share']);
        self::assertStringContainsString('not why the remote service', $finding['claim_ceiling']);
    }

    public function test_http_activity_without_material_timing_does_not_become_a_causal_claim(): void {
        $report = (new DiagnosticsAnalyzer())->analyze($this->snapshot([
            'http_requests' => [[
                'url'      => 'https://api.example.test/work',
                'duration' => 0.02,
                'blocking' => true,
                'result'   => ['response'],
                'args'     => ['method' => 'GET'],
            ]],
        ]));

        self::assertSame('insufficient_evidence', $report['synthesis']['status']);
        $finding = $this->finding($report, 'http_activity_observed');
        self::assertSame('observation', $finding['strength']);
        self::assertStringContainsString('does not support a causal latency claim', $finding['result']);
    }

    public function test_slow_database_evidence_uses_measured_query_timing(): void {
        $report = (new DiagnosticsAnalyzer())->analyze($this->snapshot([
            'queries' => [
                'queries' => [
                    ['sql' => 'SELECT * FROM wp_options', 'time' => 0.27, 'stack' => 'plugin_a'],
                    ['sql' => 'SELECT * FROM wp_posts', 'time' => 0.16, 'stack' => 'plugin_b'],
                ],
            ],
        ]));

        self::assertSame('strong_signal', $report['synthesis']['status']);
        $finding = $this->finding($report, 'measured_database_latency');
        self::assertSame(430.0, $finding['evidence']['retained_query_ms']);
        self::assertSame(270.0, $finding['evidence']['slowest_query_ms']);
        self::assertTrue($finding['evidence']['bounded_sample']);
    }

    public function test_query_timing_unavailable_is_explicit_unknown_with_next_step(): void {
        $report = (new DiagnosticsAnalyzer())->analyze($this->snapshot([
            'queries' => ['warning' => 'SAVEQUERIES disabled. Enable for precise query timing.'],
        ]));

        self::assertSame('insufficient_evidence', $report['synthesis']['status']);
        $finding = $this->finding($report, 'database_timing_unavailable');
        self::assertSame('unknown', $finding['strength']);
        self::assertFalse($finding['evidence']['timing_available']);
        self::assertStringContainsString('SAVEQUERIES', $finding['next_step']);
        self::assertNotEmpty($report['synthesis']['unknowns']);
    }

    public function test_large_autoload_and_asset_count_are_risk_signals_only(): void {
        $report = (new DiagnosticsAnalyzer())->analyze($this->snapshot([
            'system' => ['autoload_size' => 2200000, 'heavy_autoload' => []],
            'assets' => ['total_enqueued' => 55, 'heavy' => []],
        ]));

        self::assertSame('risk_signals_only', $report['synthesis']['status']);
        self::assertSame('risk_signal', $this->finding($report, 'autoload_size_risk')['strength']);
        self::assertSame('risk_signal', $this->finding($report, 'asset_count_risk')['strength']);
        self::assertStringContainsString('does not prove', $this->finding($report, 'autoload_size_risk')['meaning']);
    }

    public function test_first_slow_lifecycle_boundary_is_reported_without_internal_attribution(): void {
        $report = (new DiagnosticsAnalyzer())->analyze($this->snapshot([
            'timeline' => [
                ['layer' => 'init', 'data' => ['elapsed_since_prev' => 0.30]],
                ['layer' => 'wp_loaded', 'data' => ['elapsed_since_prev' => 0.40]],
            ],
        ]));

        self::assertSame('observed_boundary', $report['synthesis']['status']);
        $finding = $this->finding($report, 'slow_lifecycle_boundary');
        self::assertSame('init', $finding['evidence']['end_boundary']);
        self::assertStringContainsString('does not attribute', $finding['claim_ceiling']);
    }

    public function test_multiple_simultaneous_measured_signals_are_not_forced_into_one_culprit(): void {
        $report = (new DiagnosticsAnalyzer())->analyze($this->snapshot([
            'http_requests' => [[
                'url' => 'https://api.example.test/work',
                'duration' => 0.35,
                'blocking' => true,
                'result' => ['response'],
                'args' => ['method' => 'GET'],
            ]],
            'queries' => [
                'queries' => [
                    ['sql' => 'SELECT * FROM wp_posts', 'time' => 0.35, 'stack' => 'plugin_b'],
                ],
            ],
        ]));

        self::assertSame('multiple_signals', $report['synthesis']['status']);
        self::assertSame(['blocking_http_latency', 'measured_database_latency'], $report['synthesis']['strongest_finding_ids']);
        self::assertStringContainsString('cannot choose one culprit', $report['synthesis']['result']);
    }

    public function test_no_meaningful_signal_returns_honest_insufficient_evidence(): void {
        $report = (new DiagnosticsAnalyzer())->analyze($this->snapshot());

        self::assertSame('insufficient_evidence', $report['synthesis']['status']);
        $finding = $this->finding($report, 'insufficient_evidence');
        self::assertSame('unknown', $finding['strength']);
        self::assertStringContainsString('without guessing', $finding['meaning']);
    }

    public function test_cron_and_gravity_evidence_do_not_create_generic_latency_attribution(): void {
        $report = (new DiagnosticsAnalyzer())->analyze($this->snapshot([
            'cron' => [
                'qualification' => ['status' => 'completed', 'reason' => 'execution_observed'],
            ],
            'gravity' => [
                'inbox_observation' => [
                    'analysis' => ['classification' => 'TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION'],
                    'browser_analysis' => ['classification' => 'BROWSER_UI_SIGNAL_OBSERVED'],
                ],
            ],
        ]));

        self::assertSame('insufficient_evidence', $report['synthesis']['status']);
        self::assertFalse($report['synthesis']['subsystem_context']['generic_latency_attribution']);
        self::assertSame('completed', $report['synthesis']['subsystem_context']['cron']['qualification_status']);
        self::assertSame('TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION', $report['synthesis']['subsystem_context']['gravity']['server_classification']);
        self::assertSame('BROWSER_UI_SIGNAL_OBSERVED', $report['synthesis']['subsystem_context']['gravity']['browser_classification']);
    }

    public function test_synthesis_does_not_copy_raw_http_or_sql_payloads_into_findings(): void {
        $secret = 'SecretTokenCanary123456789012345';
        $report = (new DiagnosticsAnalyzer())->analyze($this->snapshot([
            'http_requests' => [[
                'url' => 'https://api.example.test/customer/42?token=' . $secret,
                'duration' => 0.40,
                'blocking' => true,
                'result' => 'Bearer ' . $secret,
                'args' => ['method' => 'GET'],
            ]],
            'queries' => [
                'queries' => [[
                    'sql' => "SELECT * FROM wp_users WHERE token = '$secret'",
                    'time' => 0.32,
                    'stack' => 'plugin_secret_' . $secret,
                ]],
            ],
        ]));

        $synthesisJson = (string) wp_json_encode($report['synthesis']);
        self::assertStringNotContainsString($secret, $synthesisJson);
        self::assertStringNotContainsString('customer/42', $synthesisJson);
        self::assertStringNotContainsString('SELECT *', $synthesisJson);
    }

    private function snapshot(array $overrides = []): array {
        return array_replace(
            [
                'meta' => [
                    'timestamp' => '2026-09-15T00:00:00Z',
                    'elapsed_ms' => 1000.0,
                    'php_version' => PHP_VERSION,
                    'context' => ['is_ajax' => false, 'is_rest' => false, 'is_cron' => false],
                ],
                'timeline' => [],
                'http_requests' => [],
                'queries' => ['queries' => []],
                'assets' => ['total_enqueued' => 0, 'heavy' => []],
                'system' => ['autoload_size' => 0, 'heavy_autoload' => []],
                'cron' => [],
                'gravity' => [],
            ],
            $overrides
        );
    }

    private function finding(array $report, string $id): array {
        foreach ( $report['synthesis']['findings'] ?? [] as $finding ) {
            if ( $id === ($finding['id'] ?? null) ) {
                return $finding;
            }
        }

        self::fail('Missing synthesis finding: ' . $id);
    }
}
