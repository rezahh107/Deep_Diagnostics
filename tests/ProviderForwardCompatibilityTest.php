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

final class ProviderForwardCompatibilityTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_options'] = [];
        $GLOBALS['wddtf_test_filters'] = [];
    }

    public function test_malformed_nested_items_are_omitted_without_hiding_later_valid_evidence(): void {
        $bundle = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/gpp-support-bundle-1.0.0-sanitized.json'),
            true,
            64,
            JSON_THROW_ON_ERROR
        );
        self::assertIsArray($bundle);

        $facts = $bundle['observed']['binding_health']['contexts'][0]['facts'];
        $facts[1]['runtime_claims'] = ['malformed-claim', ...$facts[1]['runtime_claims']];
        $bundle['observed']['binding_health']['contexts'][0]['facts'] = ['malformed-fact', ...$facts];

        $service = $this->service();
        $result = $service->importGppBundle((string) wp_json_encode($bundle));
        self::assertTrue($result['ok']);

        $unresolved = $service->diagnostics('gpp')['technical_evidence']['unresolved'];
        self::assertContains('UNBOUND', array_column($unresolved, 'state'));
        self::assertContains('NOT_PROVEN', array_column($unresolved, 'state'));
        self::assertContains('runtime_claim', array_column($unresolved, 'kind'));
    }

    public function test_direct_four_part_provider_version_survives_privacy_redaction_as_version_metadata(): void {
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers): array {
            $providers[] = [
                'contract_version' => '1.0.0',
                'provider_key' => 'four_part_provider',
                'name' => 'Four Part Provider',
                'provider_version' => '3.1.1.1',
                'schema_version' => '1.0.0',
                'capabilities' => ['health_snapshot'],
                'snapshot_callback' => static fn(): array => [
                    'schema_version' => '1.0.0',
                    'generated_at_utc' => '2026-09-16T12:00:00+00:00',
                    'components' => [],
                    'unresolved' => [],
                    'incidents' => [],
                    'recent_success' => [],
                    'privacy_boundary' => ['sensitive_values' => 'OMITTED'],
                ],
            ];
            return $providers;
        });

        $service = $this->service();
        self::assertTrue($service->captureDirect('four_part_provider')['ok']);
        $evidence = $service->diagnostics('four_part_provider')['technical_evidence'];

        self::assertSame('v3.1.1.1', $evidence['provider']['version']);
        self::assertStringNotContainsString('[redacted-ip]', (string) wp_json_encode($evidence));
    }

    private function service(): ProviderEvidenceService {
        return new ProviderEvidenceService(
            new ProviderRegistry(),
            new ProviderEvidenceStore(static fn(): int => 1789550000)
        );
    }
}
