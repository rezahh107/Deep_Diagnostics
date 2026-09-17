<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Providers\ProviderLlmExport;

final class ProviderLlmExportTest extends TestCase {
    public function test_build_groups_normalized_findings_and_keeps_empty_groups_non_authoritative(): void {
        $exporter = new ProviderLlmExport();
        $report = $exporter->build($this->diagnostics('support_bundle'));

        self::assertIsArray($report);
        self::assertSame(
            ['inbox' => 1, 'entry_detail' => 1, 'print' => 1, 'finance' => 1, 'other' => 1],
            $report['summary']['group_counts']
        );
        self::assertSame('student.full_name', $report['findings_by_surface']['entry_detail'][0]['key']);
        self::assertSame('student.program:print_mapping', $report['findings_by_surface']['print'][0]['key']);
        self::assertSame('finance.amount', $report['findings_by_surface']['finance'][0]['key']);
        self::assertSame('inbox.row_status', $report['findings_by_surface']['inbox'][0]['key']);
        self::assertSame('mystery', $report['findings_by_surface']['other'][0]['key']);
        self::assertStringContainsString('empty group is not proof', strtolower($report['grouping_note']));
    }

    public function test_support_bundle_report_discloses_source_without_retaining_upload_filename(): void {
        $exporter = new ProviderLlmExport();
        $report = $exporter->build($this->diagnostics('support_bundle'));
        self::assertIsArray($report);

        self::assertFalse($report['source']['original_upload_filename_retained']);
        self::assertSame('gpp.support_bundle', $report['source']['bundle_type']);
        self::assertStringContainsString('does not retain the original upload filename', $report['source']['source_note']);

        $markdown = $exporter->markdown($report);
        self::assertStringContainsString('Original upload filename retained: `no`', $markdown);
        self::assertStringContainsString('Entry Detail (1)', $markdown);
        self::assertStringContainsString('Print (1)', $markdown);
        self::assertStringContainsString('Finance (1)', $markdown);
        self::assertStringNotContainsString('private-support-bundle.json', $markdown);

        $json = $exporter->json($report);
        self::assertIsString($json);
        self::assertStringNotContainsString('private-support-bundle.json', $json);
        self::assertStringNotContainsString('raw_filename', $json);
    }

    public function test_direct_provider_report_never_implies_a_file_was_scanned(): void {
        $diagnostics = $this->diagnostics('direct');
        $diagnostics['technical_evidence']['source']['bundle_type'] = null;
        $report = ( new ProviderLlmExport() )->build($diagnostics);
        self::assertIsArray($report);

        self::assertSame('direct', $report['source']['acquisition_mode']);
        self::assertStringContainsString('direct provider callback', $report['source']['source_note']);
        self::assertStringContainsString('No uploaded file is implied', $report['source']['source_note']);
    }

    public function test_missing_normalized_provider_evidence_has_no_llm_export(): void {
        self::assertNull(( new ProviderLlmExport() )->build(['technical_evidence' => null]));
    }

    private function diagnostics(string $mode): array {
        return [
            'provider_key' => 'gpp',
            'history_count' => 1,
            'current_entry' => ['ingested_at_utc' => '2026-09-17T08:00:00+00:00'],
            'technical_evidence' => [
                'raw_filename' => 'private-support-bundle.json',
                'provider' => [
                    'key' => 'gpp',
                    'name' => 'Gravity Presentation Profiles',
                    'version' => '4.4.0',
                ],
                'source' => [
                    'mode' => $mode,
                    'bundle_type' => 'support_bundle' === $mode ? 'gpp.support_bundle' : null,
                    'schema_version' => '1.0.0',
                    'observed_at_utc' => '2026-09-17T07:00:00+00:00',
                ],
                'environment' => [
                    'wordpress_version' => '7.1',
                    'php_version' => '8.3.33',
                    'gravity_forms_version' => '3.1.1.1',
                    'gravity_flow_version' => '3.1.0',
                ],
                'current' => [
                    'status' => 'attention',
                    'components' => [['key' => 'print.dossier', 'status' => 'active']],
                ],
                'unresolved' => [
                    ['kind' => 'binding', 'key' => 'student.full_name', 'state' => 'UNBOUND', 'status' => 'unmapped', 'reason_code' => 'binding_unbound'],
                    ['kind' => 'runtime_claim', 'key' => 'student.program:print_mapping', 'state' => 'NOT_PROVEN'],
                    ['kind' => 'binding', 'key' => 'finance.amount', 'state' => 'UNBOUND'],
                    ['kind' => 'binding', 'key' => 'inbox.row_status', 'state' => 'UNBOUND'],
                    ['kind' => 'provider_unknown', 'key' => 'mystery', 'state' => 'UNKNOWN'],
                ],
                'privacy_boundary' => ['submitted_entry_values' => 'OMITTED'],
            ],
            'interpretation' => [
                'result' => '5 unresolved current facts are present.',
                'meaning' => 'Point-in-time provider evidence.',
                'proven' => [],
                'unresolved' => [],
                'next_step' => 'Review the unresolved GPP facts.',
                'limitations' => ['Deep Diagnostics does not change GPP state.'],
            ],
            'incidents' => [],
            'recent_success' => [],
            'comparison' => ['available' => false, 'reason' => 'previous_snapshot_unavailable', 'changes' => []],
            'correlation' => [
                'exact' => false,
                'linked' => false,
                'reason' => 'exact_correlation_ref_absent',
                'meaning' => 'No exact correlation reference was supplied.',
            ],
        ];
    }
}
