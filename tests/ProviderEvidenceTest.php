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
        $changed = ! array_key_exists($key, $GLOBALS['wddtf_test_options']) || $GLOBALS['wddtf_test_options'][$key] !== $value;
        $GLOBALS['wddtf_test_options'][$key] = $value;
        return $changed;
    }
}
if ( ! function_exists('_n') ) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string {
        return 1 === $number ? $single : $plural;
    }
}

final class ProviderEvidenceTest extends TestCase {
    private string $fixture;

    protected function setUp(): void {
        $GLOBALS['wddtf_test_options'] = [];
        $GLOBALS['wddtf_test_filters'] = [];
        $this->fixture = (string) file_get_contents(__DIR__ . '/fixtures/gpp-support-bundle-1.0.0-sanitized.json');
    }

    public function test_valid_bundle_imports_and_nested_unknowns_are_not_missed(): void {
        $service = $this->service();
        $result = $service->importGppBundle($this->fixture);
        self::assertTrue($result['ok']);
        $diagnostics = $service->diagnostics('gpp');
        $snapshot = $diagnostics['technical_evidence'];
        self::assertSame('support_bundle_loaded', $diagnostics['connection']['status']);
        self::assertContains('UNBOUND', array_column($snapshot['unresolved'], 'state'));
        self::assertContains('NOT_PROVEN', array_column($snapshot['unresolved'], 'state'));
        self::assertContains('binding', array_column($snapshot['unresolved'], 'kind'));
        self::assertContains('runtime_claim', array_column($snapshot['unresolved'], 'kind'));
        self::assertSame([], $this->bundle()['unknown_or_unproven']);
    }

    /** @dataProvider rejectedBundleProvider */
    public function test_invalid_or_unsupported_bundles_fail_safely(callable $mutate, string $expectedReason): void {
        $raw = $this->fixture;
        if ( 'invalid_json' !== $expectedReason ) {
            $bundle = $this->bundle();
            $mutate($bundle);
            $raw = (string) wp_json_encode($bundle);
        } else {
            $raw = '{bad json';
        }
        $result = $this->service()->importGppBundle($raw);
        self::assertFalse($result['ok']);
        self::assertSame($expectedReason, $result['reason']);
    }

    public static function rejectedBundleProvider(): array {
        return [
            'invalid json' => [static function(array &$bundle): void {}, 'invalid_json'],
            'wrong type' => [static function(array &$bundle): void { $bundle['bundle_type'] = 'other.bundle'; }, 'unsupported_bundle_type'],
            'unsupported schema' => [static function(array &$bundle): void { $bundle['schema_version'] = '2.0.0'; }, 'unsupported_schema_version'],
        ];
    }

    public function test_oversized_import_is_rejected(): void {
        $result = $this->service()->importGppBundle(str_repeat('x', ProviderContract::MAX_IMPORT_BYTES + 1));
        self::assertFalse($result['ok']);
        self::assertSame('import_too_large', $result['reason']);
    }

    public function test_current_snapshot_and_historical_incidents_are_distinct(): void {
        $service = $this->service();
        self::assertTrue($service->importGppBundle($this->fixture)['ok']);
        $diagnostics = $service->diagnostics('gpp');
        self::assertContains('print.dossier', array_column($diagnostics['technical_evidence']['current']['components'], 'key'));
        self::assertSame('print_surface_not_activated', $diagnostics['incidents'][0]['first_inconsistent_boundary']['reason_code']);
        self::assertStringNotContainsString('print_surface_not_activated', $diagnostics['interpretation']['result']);
        self::assertStringContainsString('historical', strtolower(implode(' ', $diagnostics['interpretation']['proven'])));
    }

    public function test_incident_order_and_first_inconsistent_boundary_are_deterministic(): void {
        $service = $this->service();
        self::assertTrue($service->importGppBundle($this->fixture)['ok']);
        $incident = $service->diagnostics('gpp')['incidents'][0];
        self::assertSame(['PRINT_DOSSIER_REQUEST', 'HOST_PRINT_CONTEXT_ADMITTED', 'PRINT_PROFILE_RESOLVED'], array_column($incident['events'], 'stage'));
        self::assertSame('PRINT_PROFILE_RESOLVED', $incident['first_inconsistent_boundary']['stage']);
        self::assertSame('FAIL', $incident['first_inconsistent_boundary']['result']);
    }

    public function test_degraded_fallback_does_not_claim_host_failure(): void {
        $service = $this->service();
        self::assertTrue($service->importGppBundle($this->fixture)['ok']);
        $incident = $service->diagnostics('gpp')['incidents'][1];
        self::assertSame('ENTRY_DETAIL_BINDING_READINESS', $incident['first_inconsistent_boundary']['stage']);
        self::assertSame('SKIP', $incident['first_inconsistent_boundary']['result']);
        self::assertStringContainsString('does not by itself mean the host plugin failed', $incident['plain_meaning']);
    }

    public function test_without_exact_ref_cross_system_causality_is_not_claimed(): void {
        $service = $this->service();
        self::assertTrue($service->importGppBundle($this->fixture)['ok']);
        $correlation = $service->diagnostics('gpp', ['layers' => ['gravity' => ['inbox_observation' => ['session_id' => 'ds-example', 'traces' => [['trace_ref' => 'trace-example']]]]]])['correlation'];
        self::assertFalse($correlation['exact']);
        self::assertFalse($correlation['linked']);
        self::assertSame('exact_correlation_ref_absent', $correlation['reason']);
    }

    public function test_provider_privacy_boundary_is_retained_but_arbitrary_pii_is_omitted(): void {
        $bundle = $this->bundle();
        $bundle['raw_request_body'] = 'CANARY-RAW-BODY-8841';
        $bundle['email'] = 'private-person@example.test';
        $bundle['observed']['binding_health']['contexts'][0]['facts'][0]['submitted_value'] = 'CANARY-NATIONAL-ID-991122';
        $bundle['observed']['diagnostics']['recent_incidents'][0]['events'][0]['exception'] = ['message' => 'CANARY-EXCEPTION-ARG-3388'];
        $service = $this->service();
        self::assertTrue($service->importGppBundle((string) wp_json_encode($bundle))['ok']);
        $snapshot = $service->diagnostics('gpp')['technical_evidence'];
        self::assertSame('OMITTED', $snapshot['privacy_boundary']['submitted_entry_values']);
        $stored = (string) wp_json_encode(get_option(ProviderContract::STORE_OPTION, []));
        foreach ( ['CANARY-RAW-BODY-8841', 'private-person@example.test', 'CANARY-NATIONAL-ID-991122', 'CANARY-EXCEPTION-ARG-3388', 'submitted_value', 'exception'] as $forbidden ) {
            self::assertStringNotContainsString($forbidden, $stored);
        }
    }

    public function test_history_is_bounded_and_exact_reimport_is_deduplicated(): void {
        $service = $this->service();
        self::assertTrue($service->importGppBundle($this->fixture)['ok']);
        self::assertSame('bundle_already_loaded', $service->importGppBundle($this->fixture)['reason']);
        self::assertSame(1, $service->diagnostics('gpp')['history_count']);
        $bundle = $this->bundle();
        for ( $i = 1; $i <= 6; ++$i ) {
            $bundle['generated_at_utc'] = sprintf('2026-09-%02dT10:00:00+00:00', $i);
            $bundle['observed']['gpp']['version'] = '0.9.' . $i;
            self::assertTrue($service->importGppBundle((string) wp_json_encode($bundle))['ok']);
        }
        self::assertSame(ProviderContract::MAX_HISTORY_PER_PROVIDER, $service->diagnostics('gpp')['history_count']);
    }

    public function test_newer_compatible_snapshot_produces_meaningful_comparison(): void {
        $service = $this->service();
        self::assertTrue($service->importGppBundle($this->fixture)['ok']);
        $bundle = $this->bundle();
        $bundle['generated_at_utc'] = '2026-09-16T10:00:00+00:00';
        $bundle['observed']['binding_health']['contexts'][0]['facts'][0]['binding_state'] = 'PROVEN';
        $bundle['observed']['binding_health']['contexts'][0]['facts'][0]['status'] = 'healthy';
        $bundle['observed']['binding_health']['contexts'][0]['facts'][0]['reason'] = null;
        $bundle['observed']['active_profiles'][] = ['surface' => 'gravity_flow.inbox', 'package_id' => 'sample-inbox-package', 'package_version' => '1.0.0', 'profile_id' => 'sample-inbox', 'artifact_type' => 'visual_profile', 'schema_version' => '1.0.0'];
        self::assertTrue($service->importGppBundle((string) wp_json_encode($bundle))['ok']);
        $comparison = $service->diagnostics('gpp')['comparison'];
        self::assertTrue($comparison['available']);
        self::assertSame('meaningful_change_observed', $comparison['reason']);
        self::assertNotEmpty($comparison['changes']);
    }

    public function test_direct_registry_proves_contract_is_not_gpp_specific(): void {
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers): array {
            $providers[] = [
                'contract_version' => '1.0.0', 'provider_key' => 'fake_provider', 'name' => 'Fake Provider',
                'provider_version' => '2.3.4', 'schema_version' => '1.0.0', 'capabilities' => ['health_snapshot'],
                'snapshot_callback' => static fn(): array => [
                    'schema_version' => '1.0.0', 'generated_at_utc' => '2026-09-16T12:00:00+00:00',
                    'environment' => ['plugin_api' => '2.0.0'], 'components' => [['key' => 'delivery.sms', 'status' => 'ready']],
                    'unresolved' => [], 'incidents' => [], 'recent_success' => [], 'privacy_boundary' => ['message_bodies' => 'OMITTED'],
                    'claim_ceiling' => 'provider_declared_evidence_only',
                ],
            ];
            return $providers;
        });
        $service = $this->service();
        self::assertTrue($service->captureDirect('fake_provider')['ok']);
        $diagnostics = $service->diagnostics('fake_provider');
        self::assertSame('direct_provider_connected', $diagnostics['connection']['status']);
        self::assertSame('fake_provider', $diagnostics['technical_evidence']['provider']['key']);
        self::assertSame('delivery.sms', $diagnostics['technical_evidence']['current']['components'][0]['key']);
    }

    private function service(): ProviderEvidenceService {
        return new ProviderEvidenceService(new ProviderRegistry(), new ProviderEvidenceStore(static fn(): int => 1789550000));
    }

    private function bundle(): array {
        $decoded = json_decode($this->fixture, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
