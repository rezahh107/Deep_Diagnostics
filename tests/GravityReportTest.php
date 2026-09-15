<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\DiagnosticsAnalyzer;
use WDDTF\Diagnostics\Report_Builder;

final class GravityReportTest extends TestCase {
    public function test_gravity_causal_trace_is_additive_in_report_markdown_and_llm_bundle(): void {
        $gravity = [
            'hosts' => [
                'gravity_forms' => ['available' => true, 'version' => 'v3.1.1.1'],
                'gravity_flow' => ['available' => true, 'version' => 'v3.1.0'],
            ],
            'inbox_observation' => [
                'status' => 'observing',
                'session_id' => 'ds-bbbbbbbbbbbbbbbb',
                'trace_count' => 1,
                'sample_count_total' => 1,
                'ajax_sample_count' => 1,
                'analysis' => [
                    'classification' => 'TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION',
                    'reason' => 'ajax_inbox_row_link_observed',
                ],
                'traces' => [
                    [
                        'trace_ref' => 'gt-0123456789abcdef',
                        'form_ref' => 'gf-0123456789abcdef',
                        'event_count_total' => 4,
                        'events_truncated' => false,
                        'events' => [
                            ['type' => 'entry_created', 'observed_at' => '2026-09-15T00:00:00Z', 'transport' => 'frontend'],
                            ['type' => 'submission_completed', 'observed_at' => '2026-09-15T00:00:01Z', 'transport' => 'frontend'],
                            [
                                'type' => 'step_started',
                                'observed_at' => '2026-09-15T00:00:02Z',
                                'transport' => 'frontend',
                                'step_ref' => 'gs-0123456789abcdef',
                                'step_type' => 'approval',
                            ],
                            [
                                'type' => 'assignees_observed',
                                'observed_at' => '2026-09-15T00:00:03Z',
                                'transport' => 'frontend',
                                'assignee_count' => 1,
                                'assignee_types' => ['user_id' => 1],
                                'assignee_refs' => ['ga-0123456789abcdef'],
                            ],
                        ],
                        'analysis' => [
                            'classification' => 'TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION',
                            'reason' => 'ajax_inbox_row_link_observed',
                            'proven' => [
                                'entry_created' => true,
                                'submission_completed' => true,
                                'step_started' => true,
                                'positive_assignee_count' => true,
                                'ajax_inbox_linked' => true,
                            ],
                            'unknowns' => [
                                'root_cause_not_inferred' => true,
                                'expected_assignee_not_compared' => true,
                            ],
                        ],
                    ],
                ],
                'samples' => [
                    [
                        'observed_at' => '2026-09-15T00:00:04Z',
                        'transport' => 'ajax',
                        'elapsed_ms' => 125.5,
                        'memory_peak_bytes' => 1048576,
                        'db_query_count' => 12,
                        'candidate_trace_refs' => ['gt-0123456789abcdef'],
                    ],
                ],
                'evidence' => [
                    'observer_hook' => 'gravityflow_columns_inbox_table',
                    'row_observer_hook' => 'gravityflow_inbox_field_value',
                    'ajax_inbox_render_observed' => true,
                    'raw_form_entry_values_stored' => false,
                    'raw_host_identifiers_stored' => false,
                ],
                'unknowns' => [
                    'client_round_trip_not_measured' => true,
                    'root_cause_not_inferred' => true,
                    'authentic_host_runtime_not_established' => true,
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
        self::assertStringContainsString('Diagnostic session: ds-bbbbbbbbbbbbbbbb', $markdown);
        self::assertStringContainsString('Candidate traces: 1', $markdown);
        self::assertStringContainsString('Session analysis: TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION', $markdown);
        self::assertStringContainsString('Gravity causal trace gt-0123456789abcdef', $markdown);
        self::assertStringContainsString('"type":"step_started"', $markdown);
        self::assertStringContainsString('"assignee_refs":["ga-0123456789abcdef"]', $markdown);
        self::assertStringNotContainsString('SecretCanary123456789012345', $markdown);
    }
}
