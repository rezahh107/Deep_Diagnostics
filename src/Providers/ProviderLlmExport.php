<?php
declare(strict_types=1);

namespace WDDTF\Providers;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Builds a bounded, privacy-safe handoff from already-normalized Provider diagnostics.
 *
 * This class never reads provider storage or raw imports. Its input is the same coherent
 * diagnostics projection used by the administrator UI.
 */
final class ProviderLlmExport {
    private const SCHEMA_VERSION = '1.0.0';
    private const GROUP_ORDER = ['inbox', 'entry_detail', 'print', 'finance', 'other'];

    public function build(array $diagnostics): ?array {
        $snapshot = is_array($diagnostics['technical_evidence'] ?? null)
            ? $diagnostics['technical_evidence']
            : null;
        if ( null === $snapshot ) {
            return null;
        }

        $source = is_array($snapshot['source'] ?? null) ? $snapshot['source'] : [];
        $provider = is_array($snapshot['provider'] ?? null) ? $snapshot['provider'] : [];
        $current = is_array($snapshot['current'] ?? null) ? $snapshot['current'] : [];
        $unresolved = is_array($snapshot['unresolved'] ?? null) ? $snapshot['unresolved'] : [];
        $incidents = is_array($diagnostics['incidents'] ?? null) ? $diagnostics['incidents'] : [];
        $recentSuccess = is_array($diagnostics['recent_success'] ?? null) ? $diagnostics['recent_success'] : [];
        $interpretation = is_array($diagnostics['interpretation'] ?? null) ? $diagnostics['interpretation'] : [];
        $groups = array_fill_keys(self::GROUP_ORDER, []);

        foreach ( $unresolved as $finding ) {
            if ( ! is_array($finding) ) {
                continue;
            }
            $groups[$this->groupForFinding($finding)][] = $this->finding($finding);
        }

        $groupCounts = [];
        foreach ( self::GROUP_ORDER as $group ) {
            $groupCounts[$group] = count($groups[$group]);
        }

        $mode = is_string($source['mode'] ?? null) ? $source['mode'] : 'unknown';
        $supportBundle = 'support_bundle' === $mode;

        return [
            'export_schema_version' => self::SCHEMA_VERSION,
            'purpose' => 'llm_diagnostic_handoff',
            'exported_by' => [
                'plugin' => 'WP Deep Diagnostics',
                'version' => defined('WDDTF_VERSION') ? WDDTF_VERSION : 'unknown',
            ],
            'source' => [
                'provider_key' => $diagnostics['provider_key'] ?? ($provider['key'] ?? null),
                'provider_name' => $provider['name'] ?? null,
                'provider_version' => $provider['version'] ?? null,
                'acquisition_mode' => $mode,
                'bundle_type' => $source['bundle_type'] ?? null,
                'source_schema_version' => $source['schema_version'] ?? null,
                'observed_at_utc' => $source['observed_at_utc'] ?? null,
                'ingested_at_utc' => is_array($diagnostics['current_entry'] ?? null)
                    ? ($diagnostics['current_entry']['ingested_at_utc'] ?? null)
                    : null,
                'original_upload_filename_retained' => false,
                'source_note' => $supportBundle
                    ? 'Evidence came from an imported provider support bundle. Deep Diagnostics intentionally does not retain the original upload filename.'
                    : 'Evidence came from a direct provider callback. No uploaded file is implied by this source mode.',
            ],
            'environment' => is_array($snapshot['environment'] ?? null) ? $snapshot['environment'] : [],
            'summary' => [
                'current_status' => $current['status'] ?? null,
                'active_component_count' => is_array($current['components'] ?? null) ? count($current['components']) : 0,
                'unresolved_or_unproven_count' => count($unresolved),
                'historical_incident_count' => count($incidents),
                'recent_success_count' => count($recentSuccess),
                'retained_snapshot_count' => (int) ($diagnostics['history_count'] ?? 0),
                'group_counts' => $groupCounts,
            ],
            'active_components' => is_array($current['components'] ?? null) ? $current['components'] : [],
            'findings_by_surface' => $groups,
            'grouping_note' => 'Surface grouping is a Deep Diagnostics presentation aid based on normalized finding keys. An empty group is not proof that the surface is healthy.',
            'current_interpretation' => [
                'result' => $interpretation['result'] ?? null,
                'meaning' => $interpretation['meaning'] ?? null,
                'proven' => is_array($interpretation['proven'] ?? null) ? $interpretation['proven'] : [],
                'unresolved' => is_array($interpretation['unresolved'] ?? null) ? $interpretation['unresolved'] : [],
                'next_step' => $interpretation['next_step'] ?? null,
            ],
            'historical_incidents' => $incidents,
            'recent_success' => $recentSuccess,
            'comparison' => is_array($diagnostics['comparison'] ?? null) ? $diagnostics['comparison'] : [],
            'correlation' => is_array($diagnostics['correlation'] ?? null) ? $diagnostics['correlation'] : [],
            'privacy_boundary' => is_array($snapshot['privacy_boundary'] ?? null) ? $snapshot['privacy_boundary'] : [],
            'limitations' => is_array($interpretation['limitations'] ?? null) ? $interpretation['limitations'] : [],
            'claim_boundary' => [
                'Treat current provider facts and historical incidents as separate evidence categories.',
                'Do not infer omitted provider stages or mappings.',
                'Do not infer causal linkage from timestamp proximity; require an explicit exact correlation reference.',
                'Do not treat an empty presentation group as proof that the corresponding surface is healthy.',
            ],
        ];
    }

    public function json(array $report): ?string {
        $json = wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json . "\n" : null;
    }

    public function markdown(array $report): string {
        $source = is_array($report['source'] ?? null) ? $report['source'] : [];
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $environment = is_array($report['environment'] ?? null) ? $report['environment'] : [];
        $groups = is_array($report['findings_by_surface'] ?? null) ? $report['findings_by_surface'] : [];
        $interpretation = is_array($report['current_interpretation'] ?? null) ? $report['current_interpretation'] : [];
        $lines = [
            '# Deep Diagnostics — Provider report for a language model',
            '',
            'Use the evidence below as point-in-time diagnostic evidence. Keep current facts separate from historical incidents and do not infer causality without an exact correlation reference.',
            '',
            '## Evidence source',
            '- Provider: ' . $this->code($source['provider_name'] ?? $source['provider_key'] ?? 'unknown'),
            '- Provider version: ' . $this->code($source['provider_version'] ?? 'unknown'),
            '- Acquisition mode: ' . $this->code($source['acquisition_mode'] ?? 'unknown'),
            '- Bundle type: ' . $this->code($source['bundle_type'] ?? 'not_applicable'),
            '- Source schema: ' . $this->code($source['source_schema_version'] ?? 'unknown'),
            '- Source observed at: ' . $this->code($source['observed_at_utc'] ?? 'unknown'),
            '- Captured by Deep Diagnostics at: ' . $this->code($source['ingested_at_utc'] ?? 'unknown'),
            '- Original upload filename retained: `no`',
            '- Source note: ' . (string) ($source['source_note'] ?? ''),
            '',
            '## Environment',
        ];

        if ( empty($environment) ) {
            $lines[] = '- No normalized environment versions were supplied.';
        } else {
            foreach ( $environment as $key => $value ) {
                $lines[] = '- ' . $this->code($key) . ': ' . $this->code($value);
            }
        }

        $lines[] = '';
        $lines[] = '## Current summary';
        $lines[] = '- Current provider status: ' . $this->code($summary['current_status'] ?? 'unknown');
        $lines[] = '- Active/current components: ' . (string) ((int) ($summary['active_component_count'] ?? 0));
        $lines[] = '- Unresolved or unproven current facts: ' . (string) ((int) ($summary['unresolved_or_unproven_count'] ?? 0));
        $lines[] = '- Historical incidents retained: ' . (string) ((int) ($summary['historical_incident_count'] ?? 0));
        $lines[] = '- Recent provider successes retained: ' . (string) ((int) ($summary['recent_success_count'] ?? 0));
        $lines[] = '- Provider snapshots retained by Deep Diagnostics: ' . (string) ((int) ($summary['retained_snapshot_count'] ?? 0));
        $lines[] = '';
        $lines[] = '### Interpretation';
        $lines[] = (string) ($interpretation['result'] ?? 'No interpretation is available.');
        if ( is_string($interpretation['meaning'] ?? null) && '' !== $interpretation['meaning'] ) {
            $lines[] = '';
            $lines[] = (string) $interpretation['meaning'];
        }

        $lines[] = '';
        $lines[] = '## Unresolved / unproven findings by surface';
        $lines[] = (string) ($report['grouping_note'] ?? '');
        foreach ( self::GROUP_ORDER as $group ) {
            $items = is_array($groups[$group] ?? null) ? $groups[$group] : [];
            $lines[] = '';
            $lines[] = '### ' . $this->groupLabel($group) . ' (' . count($items) . ')';
            if ( empty($items) ) {
                $lines[] = '- No finding was classified into this presentation group. This is not a health proof.';
                continue;
            }
            foreach ( $items as $item ) {
                if ( ! is_array($item) ) {
                    continue;
                }
                $line = '- ' . $this->code($item['key'] ?? 'unknown') . ' — state ' . $this->code($item['state'] ?? 'unknown');
                if ( is_string($item['kind'] ?? null) && '' !== $item['kind'] ) {
                    $line .= '; kind ' . $this->code($item['kind']);
                }
                if ( is_string($item['reason_code'] ?? null) && '' !== $item['reason_code'] ) {
                    $line .= '; reason ' . $this->code($item['reason_code']);
                }
                $lines[] = $line;
            }
        }

        $lines[] = '';
        $lines[] = '## Historical incidents';
        $incidents = is_array($report['historical_incidents'] ?? null) ? $report['historical_incidents'] : [];
        if ( empty($incidents) ) {
            $lines[] = '- None retained in this provider snapshot.';
        } else {
            foreach ( $incidents as $incident ) {
                if ( ! is_array($incident) ) {
                    continue;
                }
                $first = is_array($incident['first_inconsistent_boundary'] ?? null) ? $incident['first_inconsistent_boundary'] : [];
                $lines[] = '- Surface ' . $this->code($incident['surface'] ?? 'unknown')
                    . '; status ' . $this->code($incident['status'] ?? 'unknown')
                    . '; observed ' . $this->code($incident['observed_at_utc'] ?? 'unknown')
                    . '; first inconsistent boundary ' . $this->code($first['stage'] ?? 'not_recorded')
                    . '; result ' . $this->code($first['result'] ?? 'not_recorded')
                    . '; reason ' . $this->code($first['reason_code'] ?? 'not_recorded') . '.';
            }
        }

        $lines[] = '';
        $lines[] = '## Correlation boundary';
        $correlation = is_array($report['correlation'] ?? null) ? $report['correlation'] : [];
        $lines[] = '- Exact reference supplied: ' . (! empty($correlation['exact']) ? '`yes`' : '`no`');
        $lines[] = '- Linked to retained Deep Diagnostics evidence: ' . (! empty($correlation['linked']) ? '`yes`' : '`no`');
        $lines[] = '- Reason: ' . $this->code($correlation['reason'] ?? 'unknown');
        if ( is_string($correlation['meaning'] ?? null) && '' !== $correlation['meaning'] ) {
            $lines[] = '- Meaning: ' . $correlation['meaning'];
        }

        $lines[] = '';
        $lines[] = '## Privacy boundary';
        $privacy = is_array($report['privacy_boundary'] ?? null) ? $report['privacy_boundary'] : [];
        if ( empty($privacy) ) {
            $lines[] = '- No provider privacy declarations were supplied.';
        } else {
            foreach ( $privacy as $key => $value ) {
                $lines[] = '- ' . $this->code($key) . ': ' . $this->code($value);
            }
        }

        $lines[] = '';
        $lines[] = '## What to do next';
        $lines[] = (string) ($interpretation['next_step'] ?? 'Collect a fresh provider snapshot after the owning provider state changes.');
        $lines[] = '';
        $lines[] = '## Claim boundary';
        foreach ( $report['claim_boundary'] ?? [] as $boundary ) {
            if ( is_string($boundary) && '' !== $boundary ) {
                $lines[] = '- ' . $boundary;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function finding(array $finding): array {
        return [
            'kind' => is_string($finding['kind'] ?? null) ? $finding['kind'] : null,
            'key' => is_string($finding['key'] ?? null) ? $finding['key'] : null,
            'state' => is_string($finding['state'] ?? null) ? $finding['state'] : null,
            'status' => is_string($finding['status'] ?? null) ? $finding['status'] : null,
            'reason_code' => is_string($finding['reason_code'] ?? null) ? $finding['reason_code'] : null,
            'context_key' => is_string($finding['context_key'] ?? null) ? $finding['context_key'] : null,
            'binding_set_id' => is_string($finding['binding_set_id'] ?? null) ? $finding['binding_set_id'] : null,
            'binding_set_version' => is_string($finding['binding_set_version'] ?? null) ? $finding['binding_set_version'] : null,
        ];
    }

    private function groupForFinding(array $finding): string {
        $key = strtolower((string) ($finding['key'] ?? ''));
        $context = strtolower((string) ($finding['context_key'] ?? ''));

        if ( str_contains($key, ':print_mapping') || str_starts_with($key, 'print.') || str_contains($key, '.print_') ) {
            return 'print';
        }
        if ( str_starts_with($key, 'inbox.') || str_contains($key, 'gravity_flow.inbox') || str_contains($context, 'inbox') ) {
            return 'inbox';
        }
        if ( preg_match('/(^|\.)(finance|payment|payments|cheque|check|invoice|billing|transaction|transactions)(\.|:|$)/', $key) ) {
            return 'finance';
        }
        foreach ( ['student.', 'entry.', 'workflow.', 'registration.', 'guardian.', 'parent.', 'school.', 'academic.'] as $prefix ) {
            if ( str_starts_with($key, $prefix) ) {
                return 'entry_detail';
            }
        }
        return 'other';
    }

    private function groupLabel(string $group): string {
        return match ($group) {
            'inbox' => 'Inbox',
            'entry_detail' => 'Entry Detail',
            'print' => 'Print',
            'finance' => 'Finance',
            default => 'Other',
        };
    }

    private function code(mixed $value): string {
        $text = is_scalar($value) ? (string) $value : 'unknown';
        $text = str_replace(
            ["\r\n", "\r", "\n", "\t", "\u{0085}", "\u{2028}", "\u{2029}"],
            ' ',
            $text
        );

        $longestBacktickRun = 0;
        if ( preg_match_all('/`+/', $text, $matches) ) {
            foreach ( $matches[0] as $run ) {
                $longestBacktickRun = max($longestBacktickRun, strlen($run));
            }
        }

        $fence = str_repeat('`', $longestBacktickRun + 1);
        $padding = str_starts_with($text, '`') || str_ends_with($text, '`') ? ' ' : '';

        return $fence . $padding . $text . $padding . $fence;
    }
}
