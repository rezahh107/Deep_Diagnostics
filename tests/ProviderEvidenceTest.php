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

final class ProviderEvidenceTest extends TestCase {
    private string $fixture;

    protected function setUp(): void {
        $GLOBALS['wddtf_test_options'] = [];
        $GLOBALS['wddtf_test_filters'] = [];
        $this->fixture = (string) file_get_contents(__DIR__ . '/fixtures/gpp-support-bundle-1.0.0-sanitized.json');
    }

    public function test_valid_bundle_surfaces_nested_unbound_and_not_proven(): void {
        $service = $this->service();
        self::assertTrue($service->importGppBundle($this->fixture)['ok']);
        $diagnostics = $service->diagnostics('gpp');
        self::assertSame('support_bundle_loaded', $diagnostics['connection']['status']);
        self::assertSame([], $this->bundle()['unknown_or_unproven']);
        self::assertContains('UNBOUND', array_column($diagnostics['technical_evidence']['unresolved'], 'state'));
        self::assertContains('NOT_PROVEN', array_column($diagnostics['technical_evidence']['unresolved'], 'state'));
        self::assertContains('runtime_claim', array_column($diagnostics['technical_evidence']['unresolved'], 'kind'));
        self::assertSame('2026-09-15T10:00:00+00:00', $diagnostics['technical_evidence']['source']['observed_at_utc']);
        self::assertSame('2026-09-16T10:33:20+00:00', $diagnostics['current_entry']['ingested_at_utc']);
    }

    public function test_invalid_json_wrong_type_unsupported_schema_and_oversize_fail_clearly(): void {
        self::assertSame('invalid_json', $this->service()->importGppBundle('{bad')['reason']);
        $bundle = $this->bundle();
        $bundle['bundle_type'] = 'other.bundle';
        self::assertSame('unsupported_bundle_type', $this->service()->importGppBundle((string) wp_json_encode($bundle))['reason']);
        $bundle = $this->bundle();
        $bundle['schema_version'] = '2.0.0';
        self::assertSame('unsupported_schema_version', $this->service()->importGppBundle((string) wp_json_encode($bundle))['reason']);
        self::assertSame('import_too_large', $this->service()->importGppBundle(str_repeat('x', ProviderContract::MAX_IMPORT_BYTES + 1))['reason']);
    }

    public function test_current_snapshot_does_not_become_historical_incident_state(): void {
        $service = $this->service();
        self::assertTrue($service->importGppBundle($this->fixture)['ok']);
        $d = $service->diagnostics('gpp');
        self::assertContains('print.dossier', array_column($d['technical_evidence']['current']['components'], 'key'));
        self::assertSame('print_surface_not_activated', $d['incidents'][0]['first_inconsistent_boundary']['reason_code']);
        self::assertStringNotContainsString('print_surface_not_activated', $d['interpretation']['result']);
        self::assertStringContainsString('historical', strtolower(implode(' ', $d['interpretation']['proven'])));
    }

    public function test_historical_incident_remains_visible_when_current_snapshot_has_no_unresolved_fact(): void {
        $bundle = $this->bundle();
        foreach ( $bundle['observed']['binding_health']['contexts'][0]['facts'] as &$fact ) {
            $fact['status'] = 'healthy';
            $fact['reason'] = null;
            if ( 'NOT_APPLICABLE' !== $fact['binding_state'] ) {
                $fact['binding_state'] = 'PROVEN';
            }
            foreach ( $fact['runtime_claims'] as &$claim ) {
                $claim['evidence_state'] = 'PROVEN';
            }
            unset($claim);
        }
        unset($fact);

        $service = $this->service();
        self::assertTrue($service->importGppBundle((string) wp_json_encode($bundle))['ok']);
        $diagnostics = $service->diagnostics('gpp');
        self::assertSame([], $diagnostics['technical_evidence']['unresolved']);
        self::assertStringContainsString('historical provider incidents are retained separately', $diagnostics['interpretation']['result']);
        self::assertStringContainsString('not proof that the same failure is current now', $diagnostics['interpretation']['meaning']);
    }

    public function test_incident_order_first_boundary_and_degraded_meaning_are_preserved(): void {
        $service = $this->service();
        self::assertTrue($service->importGppBundle($this->fixture)['ok']);
        $incidents = $service->diagnostics('gpp')['incidents'];
        self::assertSame(['PRINT_DOSSIER_REQUEST', 'HOST_PRINT_CONTEXT_ADMITTED', 'PRINT_PROFILE_RESOLVED'], array_column($incidents[0]['events'], 'stage'));
        self::assertSame('PRINT_PROFILE_RESOLVED', $incidents[0]['first_inconsistent_boundary']['stage']);
        self::assertSame('ENTRY_DETAIL_BINDING_READINESS', $incidents[1]['first_inconsistent_boundary']['stage']);
        self::assertSame('SKIP', $incidents[1]['first_inconsistent_boundary']['result']);
        self::assertStringContainsString('does not by itself mean the host plugin failed', $incidents[1]['plain_meaning']);
    }

    public function test_absent_exact_ref_never_becomes_timing_correlation(): void {
        $service = $this->service();
        self::assertTrue($service->importGppBundle($this->fixture)['ok']);
        $correlation = $service->diagnostics('gpp', ['layers' => ['gravity' => ['inbox_observation' => ['session_id' => 'same-time-but-not-ref']]]])['correlation'];
        self::assertFalse($correlation['exact']);
        self::assertFalse($correlation['linked']);
        self::assertSame('exact_correlation_ref_absent', $correlation['reason']);
    }

    public function test_exact_direct_provider_ref_can_correlate_without_timing_guess(): void {
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers): array {
            $providers[] = self::fakeRegistration('correlated_provider', 'trace-safe-123');
            return $providers;
        });
        $service = $this->service();
        self::assertTrue($service->captureDirect('correlated_provider')['ok']);
        $correlation = $service->diagnostics(
            'correlated_provider',
            ['layers' => ['gravity' => ['inbox_observation' => ['traces' => [['trace_ref' => 'trace-safe-123']]]]]]
        )['correlation'];
        self::assertTrue($correlation['exact']);
        self::assertTrue($correlation['linked']);
        self::assertSame('exact_reference_match', $correlation['reason']);
    }

    public function test_privacy_boundary_is_retained_and_hostile_unknown_fields_and_raw_form_id_are_omitted(): void {
        $bundle = $this->bundle();
        $bundle['raw_request_body'] = 'CANARY-RAW-BODY-8841';
        $bundle['email'] = 'private-person@example.test';
        $bundle['observed']['binding_health']['contexts'][0]['facts'][0]['submitted_value'] = 'CANARY-NATIONAL-ID-991122';
        $bundle['observed']['diagnostics']['recent_incidents'][0]['events'][0]['exception'] = ['message' => 'CANARY-EXCEPTION-ARG-3388'];
        $bundle['observed']['binding_health']['contexts'][0]['form_id'] = 987654321;
        $service = $this->service();
        self::assertTrue($service->importGppBundle((string) wp_json_encode($bundle))['ok']);
        self::assertSame('OMITTED', $service->diagnostics('gpp')['technical_evidence']['privacy_boundary']['submitted_entry_values']);
        $stored = (string) wp_json_encode(get_option(ProviderContract::STORE_OPTION, []));
        foreach ( ['CANARY-RAW-BODY-8841', 'private-person@example.test', 'CANARY-NATIONAL-ID-991122', 'CANARY-EXCEPTION-ARG-3388', 'submitted_value', 'exception', '987654321', 'form_id', 'form_ref'] as $forbidden ) {
            self::assertStringNotContainsString($forbidden, $stored);
        }
    }

    public function test_history_is_bounded_deduplicated_and_comparable(): void {
        $service = $this->service();
        self::assertTrue($service->importGppBundle($this->fixture)['ok']);
        self::assertSame('bundle_already_loaded', $service->importGppBundle($this->fixture)['reason']);
        self::assertSame(1, $service->diagnostics('gpp')['history_count']);

        $bundle = $this->bundle();
        $bundle['generated_at_utc'] = '2026-09-16T10:00:00+00:00';
        $bundle['observed']['binding_health']['contexts'][0]['facts'][0]['binding_state'] = 'PROVEN';
        $bundle['observed']['binding_health']['contexts'][0]['facts'][0]['status'] = 'healthy';
        $bundle['observed']['binding_health']['contexts'][0]['facts'][0]['reason'] = null;
        $bundle['observed']['active_profiles'][] = ['surface' => 'gravity_flow.inbox', 'package_id' => 'sample-inbox-package', 'package_version' => '1.0.0', 'profile_id' => 'sample-inbox', 'artifact_type' => 'visual_profile', 'schema_version' => '1.0.0'];
        self::assertTrue($service->importGppBundle((string) wp_json_encode($bundle))['ok']);
        self::assertSame('meaningful_change_observed', $service->diagnostics('gpp')['comparison']['reason']);

        for ( $i = 17; $i <= 22; ++$i ) {
            $bundle['generated_at_utc'] = sprintf('2026-09-%02dT10:00:00+00:00', $i);
            $bundle['observed']['gpp']['version'] = '0.9.' . $i;
            self::assertTrue($service->importGppBundle((string) wp_json_encode($bundle))['ok']);
        }
        self::assertSame(ProviderContract::MAX_HISTORY_PER_PROVIDER, $service->diagnostics('gpp')['history_count']);
    }

    public function test_fake_direct_provider_proves_contract_is_generic(): void {
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers): array {
            $providers[] = self::fakeRegistration('fake_provider');
            return $providers;
        });
        $service = $this->service();
        self::assertTrue($service->captureDirect('fake_provider')['ok']);
        $d = $service->diagnostics('fake_provider');
        self::assertSame('direct_provider_connected', $d['connection']['status']);
        self::assertSame('fake_provider', $d['technical_evidence']['provider']['key']);
        self::assertSame('delivery.sms', $d['technical_evidence']['current']['components'][0]['key']);
    }

    public function test_duplicate_direct_provider_key_fails_closed_instead_of_picking_one_registration(): void {
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers): array {
            $providers[] = self::fakeRegistration('duplicate_provider');
            $providers[] = self::fakeRegistration('duplicate_provider');
            return $providers;
        });
        $service = $this->service();
        self::assertSame('direct_provider_unavailable', $service->captureDirect('duplicate_provider')['reason']);
        self::assertSame('incompatible_provider_schema', $service->diagnostics('duplicate_provider')['connection']['status']);
    }

    public function test_corrupt_provider_store_fails_closed_without_interpreting_evidence(): void {
        $GLOBALS['wddtf_test_options'][ProviderContract::STORE_OPTION] = ['value' => ['schema_version' => 'broken'], 'autoload' => false];
        $diagnostics = $this->service()->diagnostics('gpp');
        self::assertSame('evidence_store_error', $diagnostics['connection']['status']);
        self::assertNull($diagnostics['technical_evidence']);
        self::assertStringContainsString('failed closed', strtolower($diagnostics['interpretation']['meaning']));
    }

    private function service(): ProviderEvidenceService {
        return new ProviderEvidenceService(
            new ProviderRegistry(),
            new ProviderEvidenceStore(static fn(): int => 1789550000)
        );
    }

    private function bundle(): array {
        $decoded = json_decode($this->fixture, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private static function fakeRegistration(string $key, ?string $correlationRef = null): array {
        return [
            'contract_version' => '1.0.0',
            'provider_key' => $key,
            'name' => 'Fake Provider',
            'provider_version' => '2.3.4',
            'schema_version' => '1.0.0',
            'capabilities' => ['health_snapshot'],
            'snapshot_callback' => static fn(): array => [
                'schema_version' => '1.0.0',
                'generated_at_utc' => '2026-09-16T12:00:00+00:00',
                'environment' => ['plugin_api' => '2.0.0'],
                'components' => [['key' => 'delivery.sms', 'status' => 'ready']],
                'unresolved' => [],
                'incidents' => [],
                'recent_success' => [],
                'privacy_boundary' => ['message_bodies' => 'OMITTED'],
                'correlation_ref' => $correlationRef,
                'claim_ceiling' => 'provider_declared_evidence_only',
            ],
        ];
    }
}
