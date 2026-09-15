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
