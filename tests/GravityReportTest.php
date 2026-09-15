<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\DiagnosticsAnalyzer;
use WDDTF\Diagnostics\Report_Builder;

final class GravityReportTest extends TestCase {
    public function test_gravity_diagnostics_are_additive_in_report_and_markdown(): void {
        $gravity = [
            'hosts' => [
                'gravity_forms' => [
                    'available' => true,
                    'version' => '3.1.1.1',
                ],
                'gravity_flow' => [
                    'available' => true,
                    'version' => '3.1.0',
                ],
            ],
            'inbox_observation' => [
                'status' => 'observing',
                'sample_count_total' => 1,
                'ajax_sample_count' => 1,
                'samples' => [
                    [
                        'observed_at' => '2026-09-15T00:00:00Z',
                        'transport' => 'ajax',
                        'elapsed_ms' => 125.5,
                        'memory_peak_bytes' => 1048576,
                        'db_query_count' => 12,
                    ],
                ],
                'evidence' => [
                    'observer_hook' => 'gravityflow_columns_inbox_table',
                    'ajax_inbox_render_observed' => true,
                    'raw_form_entry_values_stored' => false,
                ],
                'unknowns' => [
                    'client_round_trip_not_measured' => true,
                    'root_cause_not_inferred' => true,
                ],
            ],
        ];
        $snapshot = [
            'meta' => ['timestamp' => '2026-09-15T00:00:00Z'],
            'timeline' => [],
            'http_requests' => [],
            'queries' => [],
            'assets' => ['total_enqueued' => 0, 'heavy' => []],
            'system' => ['autoload_size' => 0, 'heavy_autoload' => []],
            'cron' => [],
            'gravity' => $gravity,
        ];

        $report = (new DiagnosticsAnalyzer())->analyze($snapshot);
        $markdown = (new Report_Builder())->toMarkdown($report);

        self::assertSame($gravity, $report['layers']['gravity']);
        self::assertSame($gravity, $report['llm_bundle']['gravity']);
        self::assertStringContainsString('Gravity Forms / Gravity Flow Diagnostics', $markdown);
        self::assertStringContainsString('Gravity Flow version: 3.1.0', $markdown);
        self::assertStringContainsString('AJAX Inbox samples observed: 1', $markdown);
        self::assertStringContainsString('"transport":"ajax"', $markdown);
    }
}
