<?php
declare(strict_types=1);

namespace WDDTF\Providers {
    if ( ! function_exists(__NAMESPACE__ . '\\get_option') ) {
        function get_option(string $option, mixed $default = false): mixed {
            $reader = $GLOBALS['wddtf_test_provider_store_reader'] ?? null;
            if ( ProviderContract::STORE_OPTION === $option && is_callable($reader) ) {
                return $reader($option, $default);
            }
            return \get_option($option, $default);
        }
    }
}

namespace {
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

    final class Provider01ReadConsistencyTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['wddtf_test_options'] = [];
            $GLOBALS['wddtf_test_filters'] = [];
            $GLOBALS['wddtf_test_provider_store_reader'] = null;
            $GLOBALS['wpdb'] = new wpdb();
        }

        protected function tearDown(): void {
            $GLOBALS['wddtf_test_provider_store_reader'] = null;
        }

        public function test_diagnostics_uses_one_store_read_and_cannot_mix_old_current_with_newer_history(): void {
            [$stateA, $stateAB] = $this->buildStates(2);
            $reads = 0;
            $GLOBALS['wddtf_test_provider_store_reader'] = static function(string $option, mixed $default) use (&$reads, $stateA, $stateAB): array {
                ++$reads;
                return 1 === $reads ? $stateA : $stateAB;
            };

            $diagnostics = ( new ProviderEvidenceService(new ProviderRegistry(), new ProviderEvidenceStore()) )
                ->diagnostics('gpp');

            self::assertSame(1, $reads, 'One diagnostics projection must load Provider Store state exactly once.');
            self::assertSame('2026-09-16T12:00:00+00:00', $diagnostics['current_entry']['snapshot']['source']['observed_at_utc']);
            self::assertSame('2026-09-16T12:00:00+00:00', $diagnostics['technical_evidence']['source']['observed_at_utc']);
            self::assertNull($diagnostics['previous_entry']);
            self::assertSame(1, $diagnostics['history_count']);
            self::assertFalse($diagnostics['comparison']['available']);
            self::assertSame('previous_snapshot_unavailable', $diagnostics['comparison']['reason']);
        }

        public function test_comparison_uses_one_history_version_and_compares_exactly_a_to_b(): void {
            [, $stateAB, $stateABC] = $this->buildStates(3);
            $reads = 0;
            $GLOBALS['wddtf_test_provider_store_reader'] = static function(string $option, mixed $default) use (&$reads, $stateAB, $stateABC): array {
                ++$reads;
                return 1 === $reads ? $stateAB : $stateABC;
            };

            $comparison = ( new ProviderEvidenceStore() )->comparison('gpp');

            self::assertSame(1, $reads, 'comparison() must derive adjacent entries from one Store load.');
            self::assertTrue($comparison['available']);
            self::assertSame('2026-09-16T12:00:00+00:00', $comparison['previous_observed_at_utc']);
            self::assertSame('2026-09-16T12:01:00+00:00', $comparison['current_observed_at_utc']);
            self::assertContains(
                ['kind' => 'current_status_changed', 'before' => 'state_a', 'after' => 'state_b'],
                $comparison['changes']
            );
            self::assertStringNotContainsString('state_c', (string) wp_json_encode($comparison));
        }

        public function test_stable_two_snapshot_view_reports_b_as_current_a_as_previous_and_a_to_b_comparison(): void {
            [, $stateAB] = $this->buildStates(2);
            $reads = 0;
            $GLOBALS['wddtf_test_provider_store_reader'] = static function(string $option, mixed $default) use (&$reads, $stateAB): array {
                ++$reads;
                return $stateAB;
            };

            $view = ( new ProviderEvidenceStore() )->view('gpp');

            self::assertSame(1, $reads);
            self::assertSame(2, $view['history_count']);
            self::assertSame('2026-09-16T12:01:00+00:00', $view['current']['snapshot']['source']['observed_at_utc']);
            self::assertSame('2026-09-16T12:00:00+00:00', $view['previous']['snapshot']['source']['observed_at_utc']);
            self::assertTrue($view['comparison']['available']);
            self::assertSame('2026-09-16T12:00:00+00:00', $view['comparison']['previous_observed_at_utc']);
            self::assertSame('2026-09-16T12:01:00+00:00', $view['comparison']['current_observed_at_utc']);
            self::assertContains(
                ['kind' => 'current_status_changed', 'before' => 'state_a', 'after' => 'state_b'],
                $view['comparison']['changes']
            );
        }

        private function buildStates(int $count): array {
            $store = new ProviderEvidenceStore(
                static fn(): int => 1789550000,
                static fn(): string => 'plk-999999999999999999999999',
                static function(int $microseconds): void {}
            );
            $snapshots = [
                $this->snapshot('2026-09-16T12:00:00+00:00', 'state_a'),
                $this->snapshot('2026-09-16T12:01:00+00:00', 'state_b'),
                $this->snapshot('2026-09-16T12:02:00+00:00', 'state_c'),
            ];
            $states = [];
            for ( $index = 0; $index < $count; ++$index ) {
                self::assertTrue($store->save($snapshots[$index])['stored']);
                $state = \get_option(ProviderContract::STORE_OPTION, null);
                self::assertIsArray($state);
                $states[] = $state;
            }
            return $states;
        }

        private function snapshot(string $observedAt, string $status): array {
            return [
                'model_version' => ProviderContract::NORMALIZED_SCHEMA_VERSION,
                'provider' => [
                    'key' => 'gpp',
                    'name' => 'Gravity Presentation Profiles',
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
    }
}
