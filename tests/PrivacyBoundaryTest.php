<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\DiagnosticsAnalyzer;
use WDDTF\Diagnostics\Report_Builder;
use WDDTF\Logging\File_Logger;
use WDDTF\Privacy\Redactor;

final class PrivacyBoundaryTest extends TestCase {
    public function test_canaries_do_not_reach_json_markdown_or_llm_bundle(): void {
        $email = 'alice.canary@example.test';
        $token = 'SecretTokenCanary123456789012345';
        $snapshot = [
            'meta' => [
                'timestamp' => '2026-09-15T00:00:00Z',
                'elapsed_ms' => 120,
                'php_version' => PHP_VERSION,
                'context' => ['is_ajax' => false, 'is_rest' => false, 'is_cron' => false],
            ],
            'timeline' => [
                ['layer' => 'frontend', 'data' => ['uri' => '/account/42?email=' . $email . '&token=' . $token]],
            ],
            'http_requests' => [
                [
                    'url' => 'https://api.example.test/customer/42?email=' . $email . '&token=' . $token,
                    'duration' => 0.25,
                    'blocking' => true,
                    'result' => 'Bearer ' . $token . ' failed for ' . $email,
                    'args' => ['method' => 'GET', 'headers' => ['Authorization']],
                ],
            ],
            'queries' => [
                'queries' => [
                    ['sql' => "SELECT * FROM wp_users WHERE user_email = '$email' AND api_token = '$token' AND ID = 42", 'time' => 0.2, 'stack' => 'test'],
                ],
            ],
            'assets' => ['total_enqueued' => 0, 'heavy' => []],
            'system' => ['autoload_size' => 0, 'heavy_autoload' => []],
        ];

        $sanitized = (new Redactor())->redact($snapshot);
        $report = (new DiagnosticsAnalyzer())->analyze($sanitized);
        $markdown = (new Report_Builder())->toMarkdown($report);
        $jsonPath = (new File_Logger())->saveJson($report);
        $markdownPath = (new File_Logger())->saveMarkdown($markdown);

        $outputs = [
            (string) wp_json_encode($report),
            (string) wp_json_encode($report['llm_bundle']),
            (string) file_get_contents($jsonPath),
            (string) file_get_contents($markdownPath),
        ];

        foreach ($outputs as $output) {
            self::assertStringNotContainsString($email, $output);
            self::assertStringNotContainsString($token, $output);
            self::assertStringNotContainsString('customer/42?', $output);
        }

        self::assertSame('/account/[id]', $report['llm_bundle']['timeline'][0]['data']['uri']);
        self::assertSame('https://api.example.test/customer/[id]', $report['llm_bundle']['http_requests'][0]['url']);
        self::assertStringContainsString("user_email = '?'", $report['llm_bundle']['queries']['queries'][0]['sql']);
    }
}
