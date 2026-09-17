<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WDDTF\Cron\CronDiagnostics;
use WDDTF\Diagnostics\DiagnosticsAnalyzer;
use WDDTF\Diagnostics\ExecutionCorrelationContext;
use WDDTF\Diagnostics\Manager;
use WDDTF\Diagnostics\Report_Builder;
use WDDTF\Diagnostics\SessionStore;
use WDDTF\Privacy\Redactor;
use WDDTF\Providers\ProviderContract;
use WDDTF\Providers\ProviderEvidenceService;
use WDDTF\Providers\ProviderEvidenceStore;
use WDDTF\Providers\ProviderLlmExport;
use WDDTF\Providers\ProviderRegistry;
use WDDTF\Providers\ProviderRuntimeContext;

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

final class ExecutionCorrelationTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_actions'] = [];
        $GLOBALS['wddtf_test_filters'] = [];
        $GLOBALS['wddtf_test_options'] = [];
        $GLOBALS['wddtf_test_transients'] = [];
        $GLOBALS['wddtf_test_transient_failures'] = [];
        $GLOBALS['wddtf_test_cron_events'] = [];
        $GLOBALS['wddtf_test_ready_cron_jobs'] = [];
        $GLOBALS['wddtf_test_schedule_calls'] = [];
        $GLOBALS['wddtf_test_schedule_result'] = true;
        $GLOBALS['wddtf_test_hide_scheduled_events'] = false;
        $GLOBALS['wddtf_test_is_ajax'] = false;

        // Replace any active context left by an earlier test without generating a reference.
        ( new ExecutionCorrelationContext(static fn(int $length): string => str_repeat("\0", $length)) )->activate(false);
    }

    protected function tearDown(): void {
        $GLOBALS['wddtf_test_is_ajax'] = false;
    }

    public function test_supported_execution_generates_exactly_one_bounded_opaque_reference(): void {
        $calls = 0;
        $context = new ExecutionCorrelationContext(
            static function(int $length) use (&$calls): string {
                ++$calls;
                return str_repeat("\x01", $length);
            }
        );

        $first = $context->activate(true);
        $second = $context->establish();

        self::assertSame($first, $second);
        self::assertSame(1, $calls);
        self::assertIsString($first);
        self::assertSame(20, strlen($first));
        self::assertMatchesRegularExpression('/^dx1_[A-Za-z0-9_-]{16}$/D', $first);
        self::assertTrue(ExecutionCorrelationContext::isValidReference($first));
    }

    public function test_separate_execution_contexts_do_not_reuse_the_same_reference(): void {
        $first = ( new ExecutionCorrelationContext(static fn(int $length): string => str_repeat("\x01", $length)) )->activate(true);
        $second = ( new ExecutionCorrelationContext(static fn(int $length): string => str_repeat("\x02", $length)) )->activate(true);

        self::assertNotSame($first, $second);
    }

    public function test_generation_failure_fails_closed_without_guessable_fallback(): void {
        $calls = 0;
        $context = new ExecutionCorrelationContext(
            static function(int $length) use (&$calls): string {
                ++$calls;
                throw new RuntimeException('entropy unavailable');
            }
        );

        self::assertNull($context->activate(true));
        self::assertNull($context->establish());
        self::assertSame(1, $calls);
        self::assertNull(ProviderRuntimeContext::currentExecutionCorrelationRef());
    }

    public function test_reference_contains_no_source_pii_or_business_identifier(): void {
        $context = new ExecutionCorrelationContext(static fn(int $length): string => str_repeat("\x03", $length));
        $ref = $context->activate(true);
        self::assertIsString($ref);

        foreach ( [
            'private-person@example.test',
            'entry-987654321',
            'form-87654321',
            'https://example.test/private?token=secret',
            '192.0.2.44',
            'session-cookie-canary',
            'submitted-national-id-canary',
        ] as $forbidden ) {
            self::assertStringNotContainsString($forbidden, $ref);
        }
        self::assertMatchesRegularExpression('/^dx1_[A-Za-z0-9_-]{16}$/D', $ref);
    }

    public function test_manager_exposes_same_read_only_reference_to_provider_during_supported_request(): void {
        $context = new ExecutionCorrelationContext(static fn(int $length): string => str_repeat("\x04", $length));
        $manager = new Manager(null, null, null, $context);
        $manager->boot();

        self::assertSame($context->current(), ProviderRuntimeContext::currentExecutionCorrelationRef());
        self::assertNotNull(ProviderRuntimeContext::currentExecutionCorrelationRef());
    }

    public function test_unsupported_ajax_request_exposes_no_fake_execution_reference(): void {
        $calls = 0;
        $GLOBALS['wddtf_test_is_ajax'] = true;
        $context = new ExecutionCorrelationContext(
            static function(int $length) use (&$calls): string {
                ++$calls;
                return str_repeat("\x05", $length);
            }
        );
        $manager = new Manager(null, null, null, $context);
        $manager->boot();

        self::assertNull($context->current());
        self::assertNull(ProviderRuntimeContext::currentExecutionCorrelationRef());
        self::assertSame(0, $calls);
    }

    public function test_normal_report_pipeline_retains_reference_through_redaction_analysis_llm_and_markdown(): void {
        $context = new ExecutionCorrelationContext(static fn(int $length): string => str_repeat("\x06", $length));
        $ref = $context->activate(true);
        self::assertIsString($ref);

        $snapshot = [
            'meta' => [
                'timestamp' => '2026-09-17T12:00:00+00:00',
                'elapsed_ms' => 12.5,
                'php_version' => PHP_VERSION,
                'execution_correlation_ref' => $ref,
                'exact_reference_relationships' => [],
                'context' => ['is_ajax' => false, 'is_rest' => false, 'is_cron' => false],
            ],
            'timeline' => [],
            'http_requests' => [],
            'queries' => ['queries' => []],
            'assets' => ['total_enqueued' => 0, 'heavy' => []],
            'system' => ['autoload_size' => 0, 'heavy_autoload' => []],
            'cron' => [],
            'gravity' => [],
        ];

        $redacted = ( new Redactor() )->redact($snapshot);
        $report = ( new DiagnosticsAnalyzer() )->analyze($redacted);
        $markdown = ( new Report_Builder() )->toMarkdown($report);

        self::assertSame($ref, $report['meta']['execution_correlation_ref']);
        self::assertSame($ref, $report['llm_bundle']['meta']['execution_correlation_ref']);
        self::assertStringContainsString($ref, $markdown);
        self::assertStringNotContainsString('[redacted-token]', $ref);
    }

    public function test_fake_non_gpp_provider_reads_current_ref_and_exactly_links_to_deep_execution(): void {
        $context = new ExecutionCorrelationContext(static fn(int $length): string => str_repeat("\x07", $length));
        $manager = new Manager(null, null, null, $context);
        $manager->boot();
        $ref = ProviderRuntimeContext::currentExecutionCorrelationRef();
        self::assertIsString($ref);

        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers): array {
            $providers[] = self::registration(
                'generic_runtime_provider',
                static fn(): ?string => ProviderRuntimeContext::currentExecutionCorrelationRef()
            );
            return $providers;
        });

        $service = $this->service();
        self::assertTrue($service->captureDirect('generic_runtime_provider')['ok']);
        $diagnostics = $service->diagnostics(
            'generic_runtime_provider',
            ['meta' => ['execution_correlation_ref' => $ref]]
        );

        self::assertSame($ref, $diagnostics['technical_evidence']['correlation_ref']);
        self::assertTrue($diagnostics['correlation']['exact']);
        self::assertTrue($diagnostics['correlation']['linked']);
        self::assertSame('exact_reference_match', $diagnostics['correlation']['reason']);
        self::assertSame('deep_execution', $diagnostics['correlation']['matched_source']);
    }

    public function test_missing_different_and_close_timestamp_provider_evidence_never_links_without_exact_match(): void {
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers): array {
            $providers[] = self::registration('missing_ref_provider', static fn(): ?string => null);
            $providers[] = self::registration('different_ref_provider', static fn(): ?string => 'dx1_AAAAAAAAAAAAAAAA');
            return $providers;
        });
        $service = $this->service();
        self::assertTrue($service->captureDirect('missing_ref_provider')['ok']);
        self::assertTrue($service->captureDirect('different_ref_provider')['ok']);
        $deep = [
            'meta' => [
                'timestamp' => '2026-09-16T12:00:00+00:00',
                'execution_correlation_ref' => 'dx1_BBBBBBBBBBBBBBBB',
            ],
        ];

        $missing = $service->diagnostics('missing_ref_provider', $deep)['correlation'];
        self::assertFalse($missing['exact']);
        self::assertFalse($missing['linked']);
        self::assertSame('exact_correlation_ref_absent', $missing['reason']);

        $different = $service->diagnostics('different_ref_provider', $deep)['correlation'];
        self::assertTrue($different['exact']);
        self::assertFalse($different['linked']);
        self::assertSame('exact_reference_not_found', $different['reason']);
    }

    public function test_existing_cron_and_gravity_exact_reference_classes_remain_compatible(): void {
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers): array {
            $providers[] = self::registration('cron_provider', static fn(): ?string => 'ds-1111111111111111');
            $providers[] = self::registration('gravity_session_provider', static fn(): ?string => 'ds-2222222222222222');
            $providers[] = self::registration('gravity_trace_provider', static fn(): ?string => 'gt-3333333333333333');
            return $providers;
        });
        $service = $this->service();
        foreach ( ['cron_provider', 'gravity_session_provider', 'gravity_trace_provider'] as $key ) {
            self::assertTrue($service->captureDirect($key)['ok']);
        }
        $deep = [
            'layers' => [
                'cron' => ['qualification' => ['session_id' => 'ds-1111111111111111']],
                'gravity' => ['inbox_observation' => [
                    'session_id' => 'ds-2222222222222222',
                    'traces' => [['trace_ref' => 'gt-3333333333333333']],
                ]],
            ],
        ];

        self::assertSame('cron_qualification_session', $service->diagnostics('cron_provider', $deep)['correlation']['matched_source']);
        self::assertSame('gravity_diagnostic_session', $service->diagnostics('gravity_session_provider', $deep)['correlation']['matched_source']);
        self::assertSame('gravity_trace', $service->diagnostics('gravity_trace_provider', $deep)['correlation']['matched_source']);
    }

    public function test_exact_provider_linkage_keeps_partial_trace_partial_and_does_not_claim_browser_outcome(): void {
        $root = 'dx1_CCCCCCCCCCCCCCCC';
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers) use ($root): array {
            $providers[] = self::registration(
                'partial_trace_provider',
                static fn(): ?string => $root,
                [[
                    'schema_version' => '1.0.0',
                    'surface' => 'delivery.sms',
                    'status' => 'FAIL',
                    'observed_at_utc' => '2026-09-17T12:00:00+00:00',
                    'events' => [[
                        'seq' => 1,
                        'stage' => 'PROVIDER_DISPATCH',
                        'result' => 'FAIL',
                        'reason_code' => 'remote_rejected',
                        'fallback' => null,
                    ]],
                    'correlation_ref' => $root,
                ]]
            );
            return $providers;
        });
        $service = $this->service();
        self::assertTrue($service->captureDirect('partial_trace_provider')['ok']);
        $diagnostics = $service->diagnostics('partial_trace_provider', ['meta' => ['execution_correlation_ref' => $root]]);

        self::assertCount(1, $diagnostics['incidents'][0]['events']);
        self::assertSame('PROVIDER_DISPATCH', $diagnostics['incidents'][0]['events'][0]['stage']);
        self::assertTrue($diagnostics['incidents'][0]['correlation']['linked']);
        self::assertStringContainsString('does not prove omitted Provider stages', $diagnostics['correlation']['meaning']);
        self::assertStringContainsString('browser-visible outcome', $diagnostics['correlation']['meaning']);
        self::assertArrayNotHasKey('entry_visible_to_user_proven', $diagnostics['correlation']);
    }

    public function test_correlation_evidence_contains_no_user_content_credentials_cookies_urls_or_submitted_values(): void {
        $context = new ExecutionCorrelationContext(static fn(int $length): string => str_repeat("\x08", $length));
        $ref = $context->activate(true);
        self::assertIsString($ref);
        $evidence = [
            'execution_correlation_ref' => $ref,
            'exact_reference_relationships' => [[
                'kind' => 'gravity_diagnostic_session_created',
                'execution_correlation_ref' => $ref,
                'reference' => 'ds-4444444444444444',
            ]],
        ];
        $encoded = (string) wp_json_encode($evidence);

        foreach ( [
            'CANARY-RAW-BODY-8841',
            'private-person@example.test',
            'authorization-secret',
            'session-cookie-secret',
            'https://example.test/private?token=secret',
            'submitted-national-id-991122',
        ] as $forbidden ) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
        self::assertStringContainsString($ref, $encoded);
    }

    public function test_llm_json_and_markdown_exports_preserve_exact_linkage_claim_ceiling(): void {
        $root = 'dx1_DDDDDDDDDDDDDDDD';
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers) use ($root): array {
            $providers[] = self::registration('export_provider', static fn(): ?string => $root);
            return $providers;
        });
        $service = $this->service();
        self::assertTrue($service->captureDirect('export_provider')['ok']);
        $diagnostics = $service->diagnostics('export_provider', ['meta' => ['execution_correlation_ref' => $root]]);
        $exporter = new ProviderLlmExport();
        $report = $exporter->build($diagnostics);
        self::assertIsArray($report);
        $json = $exporter->json($report);
        $markdown = $exporter->markdown($report);
        $serviceJson = $service->exportJson('export_provider', ['meta' => ['execution_correlation_ref' => $root]]);

        foreach ( [$json, $markdown, $serviceJson] as $output ) {
            self::assertIsString($output);
            self::assertStringContainsString('does not prove omitted Provider stages', $output);
            self::assertStringContainsString('browser-visible outcome', $output);
        }
    }

    public function test_malformed_and_oversized_provider_correlation_values_are_safely_omitted(): void {
        add_filter(ProviderContract::REGISTRATION_FILTER, static function(array $providers): array {
            $providers[] = self::registration('unsafe_ref_provider', static fn(): ?string => 'https://example.test/private?token=secret');
            $providers[] = self::registration('oversized_ref_provider', static fn(): ?string => str_repeat('a', 129));
            return $providers;
        });
        $service = $this->service();
        foreach ( ['unsafe_ref_provider', 'oversized_ref_provider'] as $key ) {
            self::assertTrue($service->captureDirect($key)['ok']);
            $diagnostics = $service->diagnostics($key, ['meta' => ['execution_correlation_ref' => 'dx1_EEEEEEEEEEEEEEEE']]);
            self::assertNull($diagnostics['technical_evidence']['correlation_ref']);
            self::assertSame('exact_correlation_ref_absent', $diagnostics['correlation']['reason']);
            self::assertFalse($diagnostics['correlation']['linked']);
        }
    }

    public function test_manager_records_cron_session_creation_as_explicit_exact_relationship_not_timing_inference(): void {
        $context = new ExecutionCorrelationContext(static fn(int $length): string => str_repeat("\x09", $length));
        $store = new SessionStore(
            static fn(): int => 1000,
            static fn(): string => 'ds-5555555555555555'
        );
        $cron = new CronDiagnostics($store, static fn(): int => 1000);
        $manager = new Manager($cron, null, null, $context);
        $manager->boot();

        $result = $manager->startCronQualification();
        self::assertTrue($result['started']);
        $relationships = ( new ReflectionProperty($manager, 'executionRelationships') )->getValue($manager);

        self::assertCount(1, $relationships);
        self::assertSame('cron_qualification_session_created', $relationships[0]['kind']);
        self::assertSame($context->current(), $relationships[0]['execution_correlation_ref']);
        self::assertSame($result['qualification']['session_id'], $relationships[0]['reference']);
        self::assertArrayNotHasKey('timestamp', $relationships[0]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_manager_records_gravity_session_creation_as_explicit_exact_relationship(): void {
        define('GRAVITY_FLOW_VERSION', '3.1.0');
        $context = new ExecutionCorrelationContext(static fn(int $length): string => str_repeat("\x0A", $length));
        $manager = new Manager(null, null, null, $context);
        $manager->boot();

        $result = $manager->startGravityDiagnostic();
        self::assertTrue($result['started']);
        $relationships = ( new ReflectionProperty($manager, 'executionRelationships') )->getValue($manager);

        self::assertCount(1, $relationships);
        self::assertSame('gravity_diagnostic_session_created', $relationships[0]['kind']);
        self::assertSame($context->current(), $relationships[0]['execution_correlation_ref']);
        self::assertSame($result['observation']['session_id'], $relationships[0]['reference']);
    }

    private function service(): ProviderEvidenceService {
        return new ProviderEvidenceService(
            new ProviderRegistry(),
            new ProviderEvidenceStore(static fn(): int => 1789550000)
        );
    }

    private static function registration(
        string $key,
        callable $correlationRef,
        array $incidents = []
    ): array {
        return [
            'contract_version' => '1.0.0',
            'provider_key' => $key,
            'name' => 'Generic Fake Provider',
            'provider_version' => '2.3.4',
            'schema_version' => '1.0.0',
            'capabilities' => ['health_snapshot'],
            'snapshot_callback' => static fn(): array => [
                'schema_version' => '1.0.0',
                'generated_at_utc' => '2026-09-17T12:00:00+00:00',
                'environment' => ['plugin_api' => '2.0.0'],
                'components' => [['key' => 'delivery.sms', 'status' => 'ready']],
                'unresolved' => [],
                'incidents' => $incidents,
                'recent_success' => [],
                'privacy_boundary' => ['message_bodies' => 'OMITTED'],
                'correlation_ref' => $correlationRef(),
                'claim_ceiling' => 'provider_declared_evidence_only',
            ],
        ];
    }
}
