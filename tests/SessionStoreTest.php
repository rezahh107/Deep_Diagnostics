<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\SessionStore;

final class SessionStoreTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_transients'] = [];
        $GLOBALS['wddtf_test_transient_failures'] = [];
    }

    public function test_session_lifecycle_is_bounded_privacy_minimized_and_read_only_on_expiry(): void {
        $now = 1000;
        $store = new SessionStore(
            static function() use (&$now): int { return $now; },
            static fn(): string => 'ds-aaaaaaaaaaaaaaaa'
        );

        $session = $store->create(
            'wp_cron_qualification',
            [
                'safe' => 'value',
                'token' => 'SecretTokenCanary123456789012345',
            ],
            120
        );

        self::assertNotNull($session);
        self::assertSame('ds-aaaaaaaaaaaaaaaa', $session['id']);
        self::assertSame('[redacted]', $session['data']['token']);
        self::assertSame($session, $store->load($session['id']));

        $session['data']['safe'] = 'updated';
        self::assertTrue($store->save($session));
        self::assertSame('updated', $store->load($session['id'])['data']['safe']);

        $now = 1120;
        $before = $GLOBALS['wddtf_test_transients'];
        self::assertNull($store->load($session['id']));
        self::assertSame($before, $GLOBALS['wddtf_test_transients']);

        $store->delete($session['id']);
        self::assertSame([], $GLOBALS['wddtf_test_transients']);
    }

    public function test_malformed_persisted_session_is_rejected_without_passive_cleanup(): void {
        $store = new SessionStore(
            static fn(): int => 1000,
            static fn(): string => 'ds-bbbbbbbbbbbbbbbb'
        );
        $session = $store->create('wp_cron_qualification', ['status' => 'pending'], 120);
        self::assertNotNull($session);

        $key = array_key_first($GLOBALS['wddtf_test_transients']);
        $GLOBALS['wddtf_test_transients'][$key]['value'] = ['id' => $session['id'], 'data' => 'malformed'];
        $before = $GLOBALS['wddtf_test_transients'];

        self::assertNull($store->load($session['id']));
        self::assertSame($before, $GLOBALS['wddtf_test_transients']);
    }
}
