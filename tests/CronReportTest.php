<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Cron\CronDiagnostics;
use WDDTF\Diagnostics\DiagnosticsAnalyzer;
use WDDTF\Diagnostics\Manager;
use WDDTF\Diagnostics\Report_Builder;
use WDDTF\Diagnostics\SessionStore;
use WDDTF\Logging\File_Logger;
use WDDTF\Privacy\Redactor;

final class CronReportTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_transients'] = [];
        $GLOBALS['wddtf_test_cron_events'] = [];
        $GLOBALS['wddtf_test_ready_cron_jobs'] = [];
        $GLOBALS['wddtf_test_schedule_calls'] = [];
        $GLOBALS['wddtf_test_schedule_result'] = true;
        $GLOBALS['wddtf_test_hide_scheduled_events'] = false;
    }

    public function test_cron_section_is_additive_privacy_safe_and_available_to_all_report_outputs(): void {
        $now = 1000;
        $canary = 'SecretCronArgumentCanary123456789012345';
        $GLOBALS['wddtf_test_ready_cron_jobs'] = [
            990 => [
                'privacy_probe_hook' => [
                    'signature' => [
                        'schedule' => false,
                        'args' => [$canary, 'alice.canary@example.test'],
                    ],
                ],
            ],
        ];

        $clock = static function() use (&$now): int { return $now; };
        $cron = new CronDiagnostics(
            new SessionStore($clock, static fn(): string => 'ds-8888888888888888'),
            $clock
        );
        $started = $cron->startQualification();
        $now = 1005;
        $cron->observeProbe($started['qualification']['session_id']);

        $snapshot = [
            'meta' => ['timestamp' => '2026-09-15T00:00:00Z'],
            'timeline' => [],
            'http_requests' => [],
            'queries' => [],
            'assets' => ['total_enqueued' => 0, 'heavy' => []],
            'system' => ['autoload_size' => 0, 'heavy_autoload' => []],
            'cron' => $cron->snapshot(),
        ];

        $sanitized = (new Redactor())->redact($snapshot);
        $report = (new DiagnosticsAnalyzer())->analyze($sanitized);
        $markdown = (new Report_Builder())->toMarkdown($report);
        $jsonPath = (new File_Logger())->saveJson($report);
        $markdownPath = (new File_Logger())->saveMarkdown($markdown);

        self::assertArrayHasKey('cron', $report['layers']);
        self::assertArrayHasKey('cron', $report['llm_bundle']);
        self::assertSame('completed', $report['layers']['cron']['qualification']['status']);
        self::assertSame(5, $report['layers']['cron']['qualification']['delay_seconds']);
        self::assertStringContainsString('## WP-Cron Diagnostics', $markdown);
        self::assertStringContainsString('ds-8888888888888888', $markdown);

        $outputs = [
            (string) wp_json_encode($report),
            (string) wp_json_encode($report['llm_bundle']),
            (string) file_get_contents($jsonPath),
            (string) file_get_contents($markdownPath),
        ];

        foreach ( $outputs as $output ) {
            self::assertStringNotContainsString($canary, $output);
            self::assertStringNotContainsString('alice.canary@example.test', $output);
        }
    }

    public function test_live_admin_cron_snapshot_is_minimized_by_existing_redactor(): void {
        $now = 1000;
        $hookCanary = 'token=SecretLiveHookCanary123456789012345';
        $GLOBALS['wddtf_test_ready_cron_jobs'] = [
            999 => [
                $hookCanary => [
                    'signature' => [
                        'schedule' => false,
                        'args' => [],
                    ],
                ],
            ],
        ];

        $clock = static function() use (&$now): int { return $now; };
        $cron = new CronDiagnostics(
            new SessionStore($clock, static fn(): string => 'ds-abababababababab'),
            $clock
        );

        $live = (new Manager($cron))->getCronDiagnostics();
        $encoded = (string) wp_json_encode($live);

        self::assertStringNotContainsString('SecretLiveHookCanary123456789012345', $encoded);
        self::assertStringContainsString('token=[redacted]', $encoded);
    }
}
