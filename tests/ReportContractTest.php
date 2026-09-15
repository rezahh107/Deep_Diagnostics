<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\DiagnosticsAnalyzer;

final class ReportContractTest extends TestCase {
    public function test_report_keeps_required_top_level_contract_and_ranks_highest_signal_first(): void {
        $snapshot = [
            'meta' => ['timestamp' => '2026-09-15T00:00:00Z'],
            'timeline' => [],
            'http_requests' => [],
            'queries' => [],
            'assets' => ['total_enqueued' => 0, 'heavy' => []],
            'system' => ['autoload_size' => 2500000, 'heavy_autoload' => []],
        ];

        $report = (new DiagnosticsAnalyzer())->analyze($snapshot);

        foreach (['meta', 'layers', 'bottlenecks', 'recommendations', 'top_offenders', 'llm_bundle'] as $key) {
            self::assertArrayHasKey($key, $report);
        }

        self::assertSame('Autoload Options', $report['bottlenecks'][0]['name']);
        self::assertSame(95, $report['bottlenecks'][0]['score']);
    }
}
