<?php
declare(strict_types=1);

namespace WDDTF\Diagnostics;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Report_Builder {
    public function toMarkdown(array $report): string {
        $meta = $report['meta'] ?? [];
        $top  = $report['bottlenecks'][0] ?? [];
        $cron = $report['layers']['cron'] ?? [];
        $qualification = $cron['qualification'] ?? [];
        $ready = $cron['ready_events'] ?? [];
        $configuration = $cron['configuration'] ?? [];
        $gravity = $report['layers']['gravity'] ?? [];
        $gravityHosts = $gravity['hosts'] ?? [];
        $gravityForms = $gravityHosts['gravity_forms'] ?? [];
        $gravityFlow = $gravityHosts['gravity_flow'] ?? [];
        $inboxObservation = $gravity['inbox_observation'] ?? [];
        $traces = is_array($inboxObservation['traces'] ?? null) ? $inboxObservation['traces'] : [];
        $gravityAnalysis = $inboxObservation['analysis'] ?? [];
        $gravityIntegrity = $inboxObservation['integrity'] ?? [];

        $lines = [
            '# WP Deep Diagnostics Report',
            '- ' . __('Generated', 'wp-deep-diagnostics') . ': ' . ($meta['timestamp'] ?? ''),
            '- ' . __('Elapsed', 'wp-deep-diagnostics') . ': ' . ($meta['elapsed_ms'] ?? 0) . ' ms',
            '- ' . __('PHP', 'wp-deep-diagnostics') . ': ' . ($meta['php_version'] ?? ''),
            '- ' . __('Context', 'wp-deep-diagnostics') . ': ' . wp_json_encode($meta['context'] ?? []),
            '- ' . __('Top bottleneck', 'wp-deep-diagnostics') . ': ' . ($top['name'] ?? 'n/a'),
            '',
            '## ' . __('WP-Cron Diagnostics', 'wp-deep-diagnostics'),
            '- ' . __('Configuration mode', 'wp-deep-diagnostics') . ': ' . ($configuration['mode'] ?? 'unknown'),
            '- ' . __('Automatic WP-Cron enabled', 'wp-deep-diagnostics') . ': ' . (! empty($configuration['automatic_wp_cron_enabled']) ? 'yes' : 'no'),
            '- ' . __('Ready events observed', 'wp-deep-diagnostics') . ': ' . (int) ($ready['count'] ?? 0),
            '- ' . __('Qualification status', 'wp-deep-diagnostics') . ': ' . ($qualification['status'] ?? 'not_started'),
            '- ' . __('Diagnostic session', 'wp-deep-diagnostics') . ': ' . ($qualification['session_id'] ?? 'n/a'),
            '- ' . __('Expected execution', 'wp-deep-diagnostics') . ': ' . ($qualification['expected_at'] ?? 'n/a'),
            '- ' . __('Observed execution', 'wp-deep-diagnostics') . ': ' . ($qualification['observed_at'] ?? 'n/a'),
            '- ' . __('Measured delay', 'wp-deep-diagnostics') . ': ' . (null !== ($qualification['delay_seconds'] ?? null) ? (string) $qualification['delay_seconds'] . ' s' : 'n/a'),
            '- ' . __('Evidence', 'wp-deep-diagnostics') . ': ' . wp_json_encode($qualification['evidence'] ?? []),
            '- ' . __('Unknowns', 'wp-deep-diagnostics') . ': ' . wp_json_encode($qualification['unknowns'] ?? []),
            '',
        ];

        foreach ( array_slice($ready['events'] ?? [], 0, 10) as $event ) {
            $lines[] = '- ' . wp_json_encode(
                $event,
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
            );
        }

        $lines[] = '';
        $lines[] = '## ' . __('Gravity Forms / Gravity Flow Diagnostics', 'wp-deep-diagnostics');
        $lines[] = '- ' . __('Gravity Forms available', 'wp-deep-diagnostics') . ': ' . (! empty($gravityForms['available']) ? 'yes' : 'no');
        $lines[] = '- ' . __('Gravity Forms version', 'wp-deep-diagnostics') . ': ' . ($gravityForms['version'] ?? 'n/a');
        $lines[] = '- ' . __('Gravity Flow available', 'wp-deep-diagnostics') . ': ' . (! empty($gravityFlow['available']) ? 'yes' : 'no');
        $lines[] = '- ' . __('Gravity Flow version', 'wp-deep-diagnostics') . ': ' . ($gravityFlow['version'] ?? 'n/a');
        $lines[] = '- ' . __('Diagnostic session', 'wp-deep-diagnostics') . ': ' . ($inboxObservation['session_id'] ?? 'n/a');
        $lines[] = '- ' . __('Diagnostic status', 'wp-deep-diagnostics') . ': ' . ($inboxObservation['status'] ?? 'not_started');
        $lines[] = '- ' . __('Candidate traces', 'wp-deep-diagnostics') . ': ' . (int) ($inboxObservation['trace_count'] ?? 0);
        $lines[] = '- ' . __('Session analysis', 'wp-deep-diagnostics') . ': ' . ($gravityAnalysis['classification'] ?? 'ENTRY_NOT_OBSERVED');
        $lines[] = '- ' . __('Session integrity uncertainty', 'wp-deep-diagnostics') . ': ' . (! empty($gravityIntegrity['uncertain']) ? 'yes' : 'no');
        $lines[] = '- ' . __('Session integrity reason', 'wp-deep-diagnostics') . ': ' . ($gravityIntegrity['reason'] ?? 'n/a');
        $lines[] = '- ' . __('Inbox samples observed', 'wp-deep-diagnostics') . ': ' . (int) ($inboxObservation['sample_count_total'] ?? 0);
        $lines[] = '- ' . __('AJAX Inbox samples observed', 'wp-deep-diagnostics') . ': ' . (int) ($inboxObservation['ajax_sample_count'] ?? 0);
        $lines[] = '- ' . __('Gravity evidence', 'wp-deep-diagnostics') . ': ' . wp_json_encode($inboxObservation['evidence'] ?? []);
        $lines[] = '- ' . __('Gravity unknowns', 'wp-deep-diagnostics') . ': ' . wp_json_encode($inboxObservation['unknowns'] ?? []);
        $lines[] = '';

        foreach ( array_slice($traces, 0, 10) as $trace ) {
            $lines[] = '### ' . __('Gravity causal trace', 'wp-deep-diagnostics') . ' ' . ($trace['trace_ref'] ?? 'n/a');
            $lines[] = '- ' . __('Form reference', 'wp-deep-diagnostics') . ': ' . ($trace['form_ref'] ?? 'n/a');
            $lines[] = '- ' . __('First inconsistent point', 'wp-deep-diagnostics') . ': ' . ($trace['analysis']['classification'] ?? 'INSUFFICIENT_EVIDENCE');
            $lines[] = '- ' . __('Analysis reason', 'wp-deep-diagnostics') . ': ' . ($trace['analysis']['reason'] ?? 'unknown');
            $lines[] = '- ' . __('Complete retained history', 'wp-deep-diagnostics') . ': ' . (! empty($trace['analysis']['complete_history']) ? 'yes' : 'no');
            $lines[] = '- ' . __('Events truncated', 'wp-deep-diagnostics') . ': ' . (! empty($trace['analysis']['events_truncated']) ? 'yes' : 'no');
            $lines[] = '- ' . __('Proven facts', 'wp-deep-diagnostics') . ': ' . wp_json_encode($trace['analysis']['proven'] ?? []);
            $lines[] = '- ' . __('Unresolved facts', 'wp-deep-diagnostics') . ': ' . wp_json_encode($trace['analysis']['unknowns'] ?? []);

            foreach ( array_slice($trace['events'] ?? [], 0, 24) as $event ) {
                $lines[] = '- ' . wp_json_encode(
                    $event,
                    \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
                );
            }
            $lines[] = '';
        }

        if ( ! empty($inboxObservation['samples']) ) {
            $lines[] = '### ' . __('Observed Inbox request samples', 'wp-deep-diagnostics');
            foreach ( array_slice($inboxObservation['samples'], 0, 20) as $sample ) {
                $lines[] = '- ' . wp_json_encode(
                    $sample,
                    \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
                );
            }
        }

        $lines[] = '';
        $lines[] = '## ' . __('Recommendations', 'wp-deep-diagnostics');

        foreach ( $report['recommendations'] ?? [] as $recommendation ) {
            $lines[] = '- ' . $recommendation;
        }

        $lines[] = '';
        $lines[] = '## ' . __('Top Offenders', 'wp-deep-diagnostics');

        foreach ( $report['top_offenders'] ?? [] as $category => $items ) {
            $lines[] = '### ' . ucfirst(str_replace('_', ' ', (string) $category));

            if ( ! empty($items) ) {
                foreach ( $items as $item ) {
                    if ( isset($item['sql']) ) {
                        $item['sql'] = substr((string) $item['sql'], 0, 200);
                    }

                    $lines[] = '- ' . wp_json_encode(
                        $item,
                        \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
                    );
                }
            } else {
                $lines[] = '- ' . __('No data', 'wp-deep-diagnostics');
            }

            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }
}
