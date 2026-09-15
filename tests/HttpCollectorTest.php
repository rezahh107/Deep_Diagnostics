<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Collectors\HttpCollector;

final class HttpCollectorTest extends TestCase {
    public function test_http_fingerprint_path_does_not_depend_on_nonexistent_json_flags(): void {
        $collector = new HttpCollector();
        $args = [
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer secret'],
            'body' => ['token' => 'sensitive'],
        ];

        $collector->pre('https://example.test/api?token=sensitive', $args);
        $collector->debug(['response' => ['code' => 200]], $args, 'https://example.test/api?token=sensitive');

        $snapshot = $collector->snapshot();
        self::assertCount(1, $snapshot);
        self::assertSame('POST', $snapshot[0]['args']['method']);
        self::assertSame(['Authorization'], $snapshot[0]['args']['headers']);
    }

    public function test_wp_error_message_is_not_collected(): void {
        $collector = new HttpCollector();
        $args = ['method' => 'GET'];
        $secret = 'alice.canary@example.test SecretTokenCanary123456789012345';

        $collector->pre('https://example.test/api', $args);
        $collector->debug(new WP_Error('http_request_failed', $secret), $args, 'https://example.test/api');

        $snapshot = $collector->snapshot();
        self::assertSame('wp_error:http_request_failed', $snapshot[0]['result']);
        self::assertStringNotContainsString($secret, (string) wp_json_encode($snapshot));
    }
}
