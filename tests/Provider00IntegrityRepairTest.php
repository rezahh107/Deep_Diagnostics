<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Providers\ProviderContract;
use WDDTF\Providers\ProviderEvidenceService;
use WDDTF\Providers\ProviderEvidenceStore;
use WDDTF\Providers\ProviderRegistry;

if ( ! function_exists('apply_filters') ) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
        $callbacks = $GLOBALS['wddtf_test_filters'][$hook] ?? [];
        usort($callbacks, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
        foreach ( $callbacks as [$callback, , $acceptedArgs] ) {
            $value = $callback(...array_slice([$value, ...$args], 0, max(1, $acceptedArgs)));
        }
        return $value;
    }
}
if ( ! function_exists('update_option') ) {
    function update_option(string $key, mixed $value, mixed $autoload = null): bool {
        $existing = $GLOBALS['wddtf_test_options'][$key]['value'] ?? null;
        $changed = ! array_key_exists($key, $GLOBALS['wddtf_test_options']) || $existing !== $value;
        $GLOBALS['wddtf_test_options'][$key] = ['value' => $value, 'autoload' => is_bool($autoload) ? $autoload : null];
        return $changed;
    }
}
if ( ! function_exists('_n') ) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string {
        return 1 === $number ? $single : $plural;
    }
}

// ProviderEvidenceStore uses the same owner-safe compare/delete primitive as SessionStore.
$GLOBALS['wpdb'] = new wpdb();

final class Provider00IntegrityRepairTest extends TestCase {
    private const LOCK_OPTION = 'wddtf_provider_evidence_v1_lock';
    private string $fixture;

    protected function setUp(): void {
        $GLOBALS['wddtf_test_options'] = [];
        $GLOBALS['wddtf_test_filters'] = [];
        $GLOBALS['wpdb'] = new wpdb();
        $this->fixture = (string) file_get_contents(__DIR__ . '/fixtures/gpp-support-bundle-1.0.0-sanitized.json');
    }

    public function test_two_contending_writers_same_provider_keep_both_snapshots_and_second_reloads_committed_state(): void {
        $sawFreshState = false;
        [$first, $second] = $this->runContendedSaves(
            $this->snapshot('shared_provider', '2026-09-16T12:00:00+00:00', 'ready-a'),
            $this->snapshot('shared_provider', '2026-09-16T12:01:00+00:00', 'ready-b'),
            static function(array $state) use (&$sawFreshState): void {
                $sawFreshState = 1 === count($state['providers']['shared_provider'] ?? []);
            }
        );

        self::assertTrue($first['stored']);
        self::assertTrue($second['stored']);
        self::assertTrue($sawFreshState, 'Second writer must reload after acquiring the shared lock.');

        $history = ( new ProviderEvidenceStore(static fn(): int => 1000) )->history('shared_provider');
        self::assertCount(2, $history);
        self::assertSame(
            ['2026-09-16T12:00:00+00:00', '2026-09-16T12:01:00+00:00'],
            array_column(array_column($history, 'snapshot'), 'source') === []
                ? []
                : array_map(static fn(array $entry): string => $entry['snapshot']['source']['observed_at_utc'], $history)
        );
    }

    public function test_two_contending_writers_different_providers_do_not_overwrite_shared_option(): void {
        $secondSawFirstProvider = false;
        [$first, $second] = $this->runContendedSaves(
            $this->snapshot('provider_a', '2026-09-16T12:00:00+00:00', 'ready'),
            $this->snapshot('provider_b', '2026-09-16T12:01:00+00:00', 'ready'),
            static function(array $state) use (&$secondSawFirstProvider): void {
                $secondSawFirstProvider = isset($state['providers']['provider_a']);
            }
        );

        self::assertTrue($first['stored']);
        self::assertTrue($second['stored']);
        self::assertTrue($secondSawFirstProvider);

        $state = get_option(ProviderContract::STORE_OPTION, []);
        self::assertArrayHasKey('provider_a', $state['providers']);
        self::assertArrayHasKey('provider_b', $state['providers']);
        self::assertCount(2, $state['providers']);
    }

    public function test_lock_acquisition_failure_writes_nothing_and_service_reports_persistence_failure(): void {
        add_option(
            self::LOCK_OPTION,
            ['owner' => 'plk-aaaaaaaaaaaaaaaaaaaaaaaa', 'expires_at' => 2000],
            '',
            false
        );
        $store = new ProviderEvidenceStore(
            static fn(): int => 1000,
            static fn(): string => 'plk-bbbbbbbbbbbbbbbbbbbbbbbb',
            static function(int $microseconds): void {}
        );
        $service = new ProviderEvidenceService(new ProviderRegistry(), $store);

        $result = $service->importGppBundle($this->fixture);

        self::assertFalse($result['ok']);
        self::assertSame('provider_persistence_failed', $result['reason']);
        self::assertNull(get_option(ProviderContract::STORE_OPTION, null));
        self::assertSame('plk-aaaaaaaaaaaaaaaaaaaaaaaa', get_option(self::LOCK_OPTION)['owner']);
    }

    public function test_expired_ownership_before_commit_writes_nothing_and_service_reports_failure(): void {
        $now = 1000;
        $store = new ProviderEvidenceStore(
            static function() use (&$now): int { return $now; },
            static fn(): string => 'plk-cccccccccccccccccccccccc',
            static function(int $microseconds): void {},
            static function(string $stage, array $context) use (&$now): void {
                if ( 'before_commit' === $stage ) {
                    $now = 1031;
                }
            }
        );
        $service = new ProviderEvidenceService(new ProviderRegistry(), $store);

        $result = $service->importGppBundle($this->fixture);

        self::assertFalse($result['ok']);
        self::assertSame('provider_persistence_failed', $result['reason']);
        self::assertNull(get_option(ProviderContract::STORE_OPTION, null));
        self::assertFalse(get_option(self::LOCK_OPTION, false));
    }

    public function test_replacement_owner_before_commit_writes_nothing_and_is_not_deleted_by_stale_writer(): void {
        $replacement = ['owner' => 'plk-dddddddddddddddddddddddd', 'expires_at' => 2000];
        $store = new ProviderEvidenceStore(
            static fn(): int => 1000,
            static fn(): string => 'plk-eeeeeeeeeeeeeeeeeeeeeeee',
            static function(int $microseconds): void {},
            static function(string $stage, array $context) use ($replacement): void {
                if ( 'before_commit' === $stage ) {
                    $GLOBALS['wddtf_test_options'][self::LOCK_OPTION]['value'] = $replacement;
                }
            }
        );
        $service = new ProviderEvidenceService(new ProviderRegistry(), $store);

        $result = $service->importGppBundle($this->fixture);

        self::assertFalse($result['ok']);
        self::assertSame('provider_persistence_failed', $result['reason']);
        self::assertNull(get_option(ProviderContract::STORE_OPTION, null));
        self::assertSame($replacement, get_option(self::LOCK_OPTION));
    }

    public function test_valid_gpp_registration_is_not_poisoned_by_unkeyed_malformed_registration(): void {
        $calls = 0;
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers) use (&$calls): array {
            $providers[] = self::registration('gpp', $calls);
            $providers[] = ['name' => 'Unowned malformed registration'];
            return $providers;
        });

        $registry = new ProviderRegistry();
        $resolution = $registry->resolve('gpp');
        self::assertNull($resolution['blocking_error']);
        self::assertIsArray($resolution['provider']);
        self::assertContains('rejected', array_column($resolution['errors'], 'scope'));

        $service = new ProviderEvidenceService($registry, $this->store('plk-111111111111111111111111'));
        $diagnostics = $service->diagnostics('gpp');
        self::assertTrue($diagnostics['direct_available']);
        self::assertSame('direct_provider_available_no_snapshot', $diagnostics['connection']['status']);
        self::assertTrue($service->captureDirect('gpp')['ok']);
        self::assertSame(1, $calls);
    }

    public function test_imported_gpp_bundle_keeps_support_bundle_connection_with_unrelated_malformed_registration(): void {
        $calls = 0;
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers) use (&$calls): array {
            $providers[] = self::registration('gpp', $calls);
            $providers[] = 'malformed-unowned-registration';
            return $providers;
        });
        $service = new ProviderEvidenceService(new ProviderRegistry(), $this->store('plk-222222222222222222222222'));

        self::assertTrue($service->importGppBundle($this->fixture)['ok']);
        $diagnostics = $service->diagnostics('gpp');

        self::assertSame('support_bundle_loaded', $diagnostics['connection']['status']);
        self::assertTrue($diagnostics['direct_available']);
        self::assertSame(0, $calls);
    }

    public function test_registration_filter_exception_is_global_and_blocks_every_direct_provider(): void {
        $calls = 0;
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers) use (&$calls): array {
            $providers[] = self::registration('provider_b', $calls);
            return $providers;
        }, 10, 1);
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers): array {
            throw new RuntimeException('discovery failed');
        }, 20, 1);

        $registry = new ProviderRegistry();
        $resolution = $registry->resolve('provider_b');
        self::assertSame('global', $resolution['blocking_error']['scope']);
        self::assertSame('registration_filter_failed', $resolution['blocking_error']['reason']);
        self::assertNull($resolution['provider']);

        $service = new ProviderEvidenceService($registry, $this->store('plk-333333333333333333333333'));
        $diagnostics = $service->diagnostics('provider_b');
        self::assertFalse($diagnostics['direct_available']);
        self::assertSame('incompatible_provider_schema', $diagnostics['connection']['status']);
        self::assertSame('direct_provider_unavailable', $service->captureDirect('provider_b')['reason']);
        self::assertSame(0, $calls);
    }

    public function test_exact_provider_incompatibility_blocks_valid_same_key_registration_and_callback(): void {
        $calls = 0;
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers) use (&$calls): array {
            $providers[] = self::registration('provider_a', $calls);
            $incompatible = self::registration('provider_a', $calls);
            $incompatible['contract_version'] = '9.0.0';
            $providers[] = $incompatible;
            return $providers;
        });

        $registry = new ProviderRegistry();
        $resolution = $registry->resolve('provider_a');
        self::assertSame('provider', $resolution['blocking_error']['scope']);
        self::assertSame('incompatible_contract_version', $resolution['blocking_error']['reason']);
        self::assertNull($resolution['provider']);

        $service = new ProviderEvidenceService($registry, $this->store('plk-444444444444444444444444'));
        $diagnostics = $service->diagnostics('provider_a');
        self::assertFalse($diagnostics['direct_available']);
        self::assertSame('incompatible_provider_schema', $diagnostics['connection']['status']);
        self::assertSame('direct_provider_unavailable', $service->captureDirect('provider_a')['reason']);
        self::assertSame(0, $calls);
    }

    public function test_duplicate_valid_registrations_remain_fail_closed_and_callback_is_not_invoked(): void {
        $calls = 0;
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers) use (&$calls): array {
            $providers[] = self::registration('duplicate_provider', $calls);
            $providers[] = self::registration('duplicate_provider', $calls);
            return $providers;
        });

        $service = new ProviderEvidenceService(new ProviderRegistry(), $this->store('plk-555555555555555555555555'));
        $diagnostics = $service->diagnostics('duplicate_provider');

        self::assertFalse($diagnostics['direct_available']);
        self::assertSame('incompatible_provider_schema', $diagnostics['connection']['status']);
        self::assertSame('direct_provider_unavailable', $service->captureDirect('duplicate_provider')['reason']);
        self::assertSame(0, $calls);
    }

    public function test_provider_scoped_malformed_registration_does_not_change_other_provider_eligibility(): void {
        $callsB = 0;
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers) use (&$callsB): array {
            $providers[] = [
                'contract_version' => ProviderContract::CONTRACT_VERSION,
                'provider_key' => 'provider_a',
                'name' => '',
                'provider_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'snapshot_callback' => static fn(): array => [],
            ];
            $providers[] = self::registration('provider_b', $callsB);
            return $providers;
        });

        $registry = new ProviderRegistry();
        self::assertNotNull($registry->resolve('provider_a')['blocking_error']);
        self::assertNull($registry->resolve('provider_b')['blocking_error']);

        $service = new ProviderEvidenceService($registry, $this->store('plk-666666666666666666666666'));
        $diagnosticsB = $service->diagnostics('provider_b');
        self::assertTrue($diagnosticsB['direct_available']);
        self::assertSame('direct_provider_available_no_snapshot', $diagnosticsB['connection']['status']);
        self::assertTrue($service->captureDirect('provider_b')['ok']);
        self::assertSame(1, $callsB);
    }

    private function runContendedSaves(array $firstSnapshot, array $secondSnapshot, callable $onSecondReload): array {
        $firstPaused = false;
        $firstStore = new ProviderEvidenceStore(
            static fn(): int => 1000,
            static fn(): string => 'plk-777777777777777777777777',
            static function(int $microseconds): void {},
            static function(string $stage, array $context) use (&$firstPaused): void {
                if ( 'after_lock_acquired' === $stage && ! $firstPaused ) {
                    $firstPaused = true;
                    Fiber::suspend('first-holds-lock');
                }
            }
        );
        $secondStore = new ProviderEvidenceStore(
            static fn(): int => 1000,
            static fn(): string => 'plk-888888888888888888888888',
            static function(int $microseconds): void {
                Fiber::suspend('second-waits-for-lock');
            },
            static function(string $stage, array $context) use ($onSecondReload): void {
                if ( 'after_state_reload' === $stage ) {
                    $onSecondReload($context['state']);
                }
            }
        );

        $first = new Fiber(static fn(): array => $firstStore->save($firstSnapshot));
        $second = new Fiber(static fn(): array => $secondStore->save($secondSnapshot));

        self::assertSame('first-holds-lock', $first->start());
        self::assertSame('second-waits-for-lock', $second->start());
        $first->resume();
        self::assertTrue($first->isTerminated());
        $second->resume();
        self::assertTrue($second->isTerminated());

        return [$first->getReturn(), $second->getReturn()];
    }

    private function store(string $lockToken): ProviderEvidenceStore {
        return new ProviderEvidenceStore(
            static fn(): int => 1789550000,
            static fn(): string => $lockToken,
            static function(int $microseconds): void {}
        );
    }

    private function snapshot(string $providerKey, string $observedAt, string $status): array {
        return [
            'model_version' => ProviderContract::NORMALIZED_SCHEMA_VERSION,
            'provider' => [
                'key' => $providerKey,
                'name' => 'Provider ' . $providerKey,
                'version' => 'v1.0.0',
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

    private static function registration(string $key, int &$calls): array {
        return [
            'contract_version' => ProviderContract::CONTRACT_VERSION,
            'provider_key' => $key,
            'name' => 'Test Provider ' . $key,
            'provider_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'capabilities' => ['health_snapshot'],
            'snapshot_callback' => static function() use (&$calls): array {
                ++$calls;
                return [
                    'schema_version' => '1.0.0',
                    'generated_at_utc' => '2026-09-16T12:00:00+00:00',
                    'components' => [],
                    'unresolved' => [],
                    'incidents' => [],
                    'recent_success' => [],
                    'privacy_boundary' => ['sensitive_values' => 'OMITTED'],
                ];
            },
        ];
    }
}
