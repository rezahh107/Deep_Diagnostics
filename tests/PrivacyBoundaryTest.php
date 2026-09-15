<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\DiagnosticsAnalyzer;
use WDDTF\Diagnostics\Report_Builder;
use WDDTF\Logging\File_Logger;
use WDDTF\Privacy\Redactor;

final class PrivacyBoundaryTest extends TestCase {
    public function test_direct_malformed_url_is_terminally_redacted_for_url_like_keys(): void {
        $secret = 'SecretTokenCanary123456789012345';
        $malformed = 'https://?token=' . $secret;
        $redactor = new Redactor();

        foreach (['url', 'uri', 'src'] as $key) {
            $sanitized = $redactor->redact([$key => $malformed]);
            $encoded = (string) wp_json_encode($sanitized);

            self::assertSame('[redacted]', $sanitized[$key]);
            self::assertStringNotContainsString($secret, $encoded);
            self::assertStringNotContainsString($malformed, $encoded);
        }
    }

    public function test_embedded_malformed_url_is_terminally_redacted(): void {
        $secret = 'SecretTokenCanary123456789012345';
        $sanitized = (new Redactor())->redact([
            'message' => 'Request failed for https://?token=' . $secret . ' after retry.',
        ]);

        self::assertSame('Request failed for [redacted] after retry.', $sanitized['message']);
        self::assertStringNotContainsString($secret, $sanitized['message']);
    }

    public function test_additional_malformed_url_shape_is_terminally_redacted(): void {
        $sanitized = (new Redactor())->redact(['url' => 'http://foo:bar']);

        self::assertSame('[redacted]', $sanitized['url']);
    }

    public function test_valid_absolute_url_keeps_existing_minimization_behavior(): void {
        $secret = 'SecretTokenCanary123456789012345';
        $sanitized = (new Redactor())->redact([
            'url' => 'HTTPS://API.EXAMPLE.TEST/customer/42?token=' . $secret . '#fragment',
        ]);

        self::assertSame('https://api.example.test/customer/[id]', $sanitized['url']);
        self::assertStringNotContainsString($secret, $sanitized['url']);
    }

    public function test_relative_uri_keeps_existing_minimization_behavior(): void {
        $sanitized = (new Redactor())->redact([
            'uri' => '/account/42?token=SecretTokenCanary123456789012345',
        ]);

        self::assertSame('/account/[id]', $sanitized['uri']);
    }

    public function test_canaries_do_not_reach_synthesis_json_markdown_or_llm_bundle(): void {
        $email = 'alice.canary@example.test';
        $token = 'SecretTokenCanary123456789012345';
        $snapshot = [
            'meta' => [
                'timestamp' => '2026-09-15T00:00:00Z',
                'elapsed_ms' => 1000,
                'php_version' => PHP_VERSION,
                'context' => ['is_ajax' => false, 'is_rest' => false, 'is_cron' => false],
            ],
            'timeline' => [
                ['layer' => 'frontend', 'data' => ['uri' => '/account/42?email=' . $email . '&token=' . $token]],
            ],
            'http_requests' => [
                [
                    'url' => 'https://api.example.test/customer/42?email=' . $email . '&token=' . $token,
                    'duration' => 0.45,
                    'blocking' => true,
                    'result' => 'Bearer ' . $token . ' failed for ' . $email,
                    'args' => ['method' => 'GET', 'headers' => ['Authorization']],
                ],
            ],
            'queries' => [
                'queries' => [
                    ['sql' => "SELECT * FROM wp_users WHERE user_email = '$email' AND api_token = '$token' AND ID = 42", 'time' => 0.35, 'stack' => 'test'],
                ],
            ],
            'assets' => ['total_enqueued' => 0, 'heavy' => []],
            'system' => ['autoload_size' => 0, 'heavy_autoload' => []],
            'cron' => [],
            'gravity' => [],
        ];

        $sanitized = (new Redactor())->redact($snapshot);
        $report = (new DiagnosticsAnalyzer())->analyze($sanitized);
        $markdown = (new Report_Builder())->toMarkdown($report);
        $jsonPath = (new File_Logger())->saveJson($report);
        $markdownPath = (new File_Logger())->saveMarkdown($markdown);

        $outputs = [
            (string) wp_json_encode($report),
            (string) wp_json_encode($report['synthesis']),
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
        self::assertSame($report['synthesis'], $report['llm_bundle']['synthesis']);
        self::assertSame('multiple_signals', $report['synthesis']['status']);
    }
}
