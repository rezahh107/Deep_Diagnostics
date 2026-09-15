<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\SessionStore;

final class SessionStoreTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_transients'] = [];
        $GLOBALS['wddtf_test_transient_failures'] = [];
        $GLOBALS['wddtf_test_options'] = [];
        $GLOBALS['wpdb'] = new wpdb();
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

    public function test_serialized_mutation_reloads_fresh_state_after_lock_acquisition(): void {
        $store = $this->makeStore('ds-1111111111111111', 'lk-111111111111111111111111');
        $session = $store->create('gravityflow_inbox_observation', ['events' => []], 900);
        self::assertNotNull($session);

        $staleSnapshot = $store->load($session['id']);
        self::assertNotNull($staleSnapshot);

        $fresh = $staleSnapshot;
        $fresh['data']['events'][] = 'committed-before-lock';
        self::assertTrue($store->save($fresh));

        $sawFreshState = false;
        self::assertTrue(
            $store->mutate(
                $session['id'],
                static function(array $locked) use (&$sawFreshState): array {
                    $sawFreshState = ['committed-before-lock'] === ($locked['data']['events'] ?? []);
                    $locked['data']['events'][] = 'serialized-mutation';
                    return $locked;
                }
            )
        );

        self::assertTrue($sawFreshState);
        self::assertSame(
            ['committed-before-lock', 'serialized-mutation'],
            $store->load($session['id'])['data']['events']
        );
    }

    public function test_serialized_mutation_closes_stale_interleaving_that_loses_one_trace(): void {
        $store = $this->makeStore('ds-2222222222222222', 'lk-222222222222222222222222');
        $session = $store->create('gravityflow_inbox_observation', ['traces' => []], 900);
        self::assertNotNull($session);

        // Deterministic reproduction of the pre-fix whole-session lost-update interleaving.
        $writerA = $store->load($session['id']);
        $writerB = $store->load($session['id']);
        self::assertNotNull($writerA);
        self::assertNotNull($writerB);
        $writerA['data']['traces']['trace-a'] = ['event' => 'a'];
        $writerB['data']['traces']['trace-b'] = ['event' => 'b'];
        self::assertTrue($store->save($writerA));
        self::assertTrue($store->save($writerB));
        self::assertSame(['trace-b'], array_keys($store->load($session['id'])['data']['traces']));

        $store->delete($session['id']);
        $session = $store->create('gravityflow_inbox_observation', ['traces' => []], 900);
        self::assertNotNull($session);

        foreach ( ['trace-a', 'trace-b'] as $trace ) {
            self::assertTrue(
                $store->mutate(
                    $session['id'],
                    static function(array $locked) use ($trace): array {
                        $locked['data']['traces'][$trace] = ['event' => substr($trace, -1)];
                        return $locked;
                    }
                )
            );
        }

        self::assertSame(
            ['trace-a', 'trace-b'],
            array_keys($store->load($session['id'])['data']['traces'])
        );
    }

    public function test_lock_ownership_prevents_writer_from_releasing_replacement_lock(): void {
        $store = $this->makeStore('ds-3333333333333333', 'lk-333333333333333333333333');
        $session = $store->create('gravityflow_inbox_observation', ['count' => 0], 900);
        self::assertNotNull($session);
        $lockKey = $this->lockKey($session['id']);
        $replacement = [
            'owner' => 'lk-aaaaaaaaaaaaaaaaaaaaaaaa',
            'expires_at' => 2000,
        ];

        $result = $store->mutate(
            $session['id'],
            static function(array $locked) use ($lockKey, $replacement): array {
                // Simulate a replacement owner appearing before the first owner releases.
                $GLOBALS['wddtf_test_options'][$lockKey]['value'] = $replacement;
                ++$locked['data']['count'];
                return $locked;
            }
        );

        self::assertFalse($result);
        self::assertSame($replacement, get_option($lockKey));
        self::assertTrue($store->integrityStatus($session['id'])['uncertain']);
        self::assertSame('mutation_lock_release_failed', $store->integrityStatus($session['id'])['reason']);
    }

    public function test_stale_lock_recovery_is_owner_safe_and_bounded(): void {
        $now = 1000;
        $sleepCalls = 0;
        $store = new SessionStore(
            static function() use (&$now): int { return $now; },
            static fn(): string => 'ds-4444444444444444',
            static fn(): string => 'lk-444444444444444444444444',
            static function(int $microseconds) use (&$sleepCalls): void { ++$sleepCalls; }
        );
        $session = $store->create('gravityflow_inbox_observation', ['count' => 0], 900);
        self::assertNotNull($session);
        $lockKey = $this->lockKey($session['id']);

        add_option(
            $lockKey,
            ['owner' => 'lk-bbbbbbbbbbbbbbbbbbbbbbbb', 'expires_at' => 999],
            '',
            false
        );

        self::assertTrue(
            $store->mutate(
                $session['id'],
                static function(array $locked): array {
                    ++$locked['data']['count'];
                    return $locked;
                }
            )
        );
        self::assertFalse(get_option($lockKey, false));
        self::assertSame(0, $sleepCalls);

        add_option(
            $lockKey,
            ['owner' => 'lk-cccccccccccccccccccccccc', 'expires_at' => 2000],
            '',
            false
        );
        self::assertFalse($store->mutate($session['id'], static fn(array $locked): array => $locked));
        self::assertSame(7, $sleepCalls);
        self::assertSame('lk-cccccccccccccccccccccccc', get_option($lockKey)['owner']);
        self::assertSame('mutation_lock_unavailable', $store->integrityStatus($session['id'])['reason']);
    }

    public function test_persistence_failure_marks_integrity_uncertain_outside_failed_session_write(): void {
        $store = $this->makeStore('ds-5555555555555555', 'lk-555555555555555555555555');
        $session = $store->create('gravityflow_inbox_observation', ['count' => 0], 900);
        self::assertNotNull($session);

        $sessionKey = array_key_first($GLOBALS['wddtf_test_transients']);
        self::assertIsString($sessionKey);
        $GLOBALS['wddtf_test_transient_failures'][] = $sessionKey;

        self::assertFalse(
            $store->mutate(
                $session['id'],
                static function(array $locked): array {
                    ++$locked['data']['count'];
                    return $locked;
                }
            )
        );

        $integrity = $store->integrityStatus($session['id']);
        self::assertTrue($integrity['uncertain']);
        self::assertSame('mutation_persistence_failed', $integrity['reason']);
        self::assertSame(0, $store->load($session['id'])['data']['count']);
    }

    private function makeStore(string $sessionId, string $lockToken): SessionStore {
        return new SessionStore(
            static fn(): int => 1000,
            static fn(): string => $sessionId,
            static fn(): string => $lockToken,
            static function(int $microseconds): void {}
        );
    }

    private function lockKey(string $sessionId): string {
        return 'wddtf_diag_lock_' . substr(hash('sha256', $sessionId), 0, 32);
    }
}
