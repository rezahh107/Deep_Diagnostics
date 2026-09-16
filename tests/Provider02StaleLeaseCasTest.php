<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Providers\ProviderContract;
use WDDTF\Providers\ProviderEvidenceStore;

final class Provider02StaleLeaseCasTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_options'] = [];
        $GLOBALS['wddtf_test_filters'] = [];
        $GLOBALS['wpdb'] = new wpdb();
    }

    public function test_stale_same_provider_writer_cannot_overwrite_newer_commit_after_final_owner_check(): void {
        $now = 1000;
        $this->store($now, '1')->save($this->snapshot('shared', '2026-09-16T12:00:00+00:00', 'base'));
        $competingCommitted = false;

        $stale = new ProviderEvidenceStore(
            static function() use (&$now): int { return $now; },
            static fn(): string => self::token('a'),
            static function(int $microseconds): void {},
            function(string $stage, array $context) use (&$now, &$competingCommitted): void {
                if ( 'after_commit_ownership_check' !== $stage || $competingCommitted ) {
                    return;
                }
                $competingCommitted = true;
                $now = 1031;
                $newer = $this->store($now, 'b');
                self::assertTrue($newer->save($this->snapshot('shared', '2026-09-16T12:02:00+00:00', 'newer'))['stored']);
            }
        );

        try {
            $stale->save($this->snapshot('shared', '2026-09-16T12:01:00+00:00', 'stale'));
            self::fail('Stale writer must fail closed after the newer Store state commits.');
        } catch (RuntimeException) {
            self::assertTrue($competingCommitted);
        }

        $history = ( new ProviderEvidenceStore(static fn(): int => $now) )->history('shared');
        self::assertCount(2, $history);
        self::assertSame('newer', $history[1]['snapshot']['current']['status']);
        self::assertFalse(get_option(ProviderContract::STORE_OPTION . '_lock', false));
    }

    public function test_stale_different_provider_writer_cannot_replace_shared_option_created_by_newer_writer(): void {
        $now = 1000;
        $competingCommitted = false;

        $stale = new ProviderEvidenceStore(
            static function() use (&$now): int { return $now; },
            static fn(): string => self::token('c'),
            static function(int $microseconds): void {},
            function(string $stage, array $context) use (&$now, &$competingCommitted): void {
                if ( 'after_commit_ownership_check' !== $stage || $competingCommitted ) {
                    return;
                }
                $competingCommitted = true;
                $now = 1031;
                $newer = $this->store($now, 'd');
                self::assertTrue($newer->save($this->snapshot('provider_b', '2026-09-16T12:02:00+00:00', 'newer'))['stored']);
            }
        );

        try {
            $stale->save($this->snapshot('provider_a', '2026-09-16T12:01:00+00:00', 'stale'));
            self::fail('Stale writer must not replace a Store created after its absent-state read.');
        } catch (RuntimeException) {
            self::assertTrue($competingCommitted);
        }

        $state = get_option(ProviderContract::STORE_OPTION, []);
        self::assertArrayHasKey('provider_b', $state['providers']);
        self::assertArrayNotHasKey('provider_a', $state['providers']);
        self::assertFalse(get_option(ProviderContract::STORE_OPTION . '_lock', false));
    }

    public function test_stale_reset_cannot_delete_evidence_committed_by_newer_writer(): void {
        $now = 1000;
        $this->store($now, '2')->save($this->snapshot('shared', '2026-09-16T12:00:00+00:00', 'base'));
        $competingCommitted = false;

        $staleReset = new ProviderEvidenceStore(
            static function() use (&$now): int { return $now; },
            static fn(): string => self::token('e'),
            static function(int $microseconds): void {},
            function(string $stage, array $context) use (&$now, &$competingCommitted): void {
                if ( 'after_reset_ownership_check' !== $stage || $competingCommitted ) {
                    return;
                }
                $competingCommitted = true;
                $now = 1031;
                $newer = $this->store($now, 'f');
                self::assertTrue($newer->save($this->snapshot('shared', '2026-09-16T12:03:00+00:00', 'newer'))['stored']);
            }
        );

        try {
            $staleReset->reset();
            self::fail('Stale reset must fail closed after newer evidence commits.');
        } catch (RuntimeException) {
            self::assertTrue($competingCommitted);
        }

        $history = ( new ProviderEvidenceStore(static fn(): int => $now) )->history('shared');
        self::assertCount(2, $history);
        self::assertSame('newer', $history[1]['snapshot']['current']['status']);
        self::assertFalse(get_option(ProviderContract::STORE_OPTION . '_lock', false));
    }

    public function test_uncontended_mutation_controls_preserve_dedup_retention_cap_corruption_reset_and_lock_release(): void {
        $now = 1000;
        $store = $this->store($now, '3');
        $first = $this->snapshot('bounded', '2026-09-16T12:00:00+00:00', 's0');

        self::assertTrue($store->save($first)['stored']);
        self::assertTrue($store->save($first)['duplicate']);
        self::assertFalse(get_option(ProviderContract::STORE_OPTION . '_lock', false));

        for ( $i = 1; $i <= 6; ++$i ) {
            ++$now;
            self::assertTrue($store->save($this->snapshot(
                'bounded',
                sprintf('2026-09-16T12:%02d:00+00:00', $i),
                's' . $i
            ))['stored']);
        }
        self::assertCount(ProviderContract::MAX_HISTORY_PER_PROVIDER, $store->history('bounded'));

        $store->reset();
        self::assertNull(get_option(ProviderContract::STORE_OPTION, null));
        self::assertFalse(get_option(ProviderContract::STORE_OPTION . '_lock', false));

        for ( $i = 0; $i < ProviderContract::MAX_PROVIDERS; ++$i ) {
            ++$now;
            self::assertTrue($store->save($this->snapshot(
                'provider_' . $i,
                sprintf('2026-09-17T%02d:00:00+00:00', $i),
                'ready'
            ))['stored']);
        }
        $this->expectException(RuntimeException::class);
        try {
            $store->save($this->snapshot('provider_over_cap', '2026-09-17T12:30:00+00:00', 'ready'));
        } finally {
            self::assertFalse(get_option(ProviderContract::STORE_OPTION . '_lock', false));
            $GLOBALS['wddtf_test_options'][ProviderContract::STORE_OPTION] = [
                'value' => ['schema_version' => 'broken'],
                'autoload' => false,
            ];
            try {
                $store->view('bounded');
                self::fail('Corrupt Provider Store must fail closed.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    private function store(int &$now, string $tokenChar): ProviderEvidenceStore {
        return new ProviderEvidenceStore(
            static function() use (&$now): int { return $now; },
            static fn(): string => self::token($tokenChar),
            static function(int $microseconds): void {}
        );
    }

    private static function token(string $char): string {
        return 'plk-' . str_repeat($char, 24);
    }

    private function snapshot(string $providerKey, string $observedAt, string $status): array {
        return [
            'model_version' => ProviderContract::NORMALIZED_SCHEMA_VERSION,
            'provider' => [
                'key' => $providerKey,
                'name' => 'Provider ' . $providerKey,
                'version' => '1.0.0',
            ],
            'source' => [
                'mode' => 'direct',
                'schema_version' => '1.0.0',
                'observed_at_utc' => $observedAt,
            ],
            'environment' => [],
            'current' => [
                'status' => $status,
                'components' => [],
            ],
            'unresolved' => [],
            'incidents' => [],
            'recent_success' => [],
            'privacy_boundary' => ['sensitive_values' => 'OMITTED'],
            'correlation_ref' => null,
            'claim_ceiling' => 'provider_declared_evidence_only',
        ];
    }
}
