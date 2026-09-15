<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\DiagnosticsAnalyzer;

final class ReportContractTest extends TestCase {
    public function test_report_keeps_required_top_level_contract_and_legacy_ranking_while_adding_synthesis(): void {
        $snapshot = [
            'meta' => ['timestamp' => '2026-09-15T00:00:00Z', 'elapsed_ms' => 1000.0],
            'timeline' => [],
            'http_requests' => [],
            'queries' => ['queries' => []],
            'assets' => ['total_enqueued' => 0, 'heavy' => []],
            'system' => ['autoload_size' => 2500000, 'heavy_autoload' => []],
            'cron' => [],
            'gravity' => [],
        ];

        $report = (new DiagnosticsAnalyzer())->analyze($snapshot);

        foreach (['meta', 'layers', 'synthesis', 'bottlenecks', 'recommendations', 'top_offenders', 'llm_bundle'] as $key) {
            self::assertArrayHasKey($key, $report);
        }

        // Existing consumers keep the historical heuristic contract unchanged.
        self::assertSame('Autoload Options', $report['bottlenecks'][0]['name']);
        self::assertSame(95, $report['bottlenecks'][0]['score']);

        // New consumers get the evidence-first interpretation additively.
        self::assertSame('risk_signals_only', $report['synthesis']['status']);
        self::assertSame($report['synthesis'], $report['llm_bundle']['synthesis']);
    }
}
