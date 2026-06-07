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

        $lines = [
            '# WP Deep Diagnostics Report',
            '- ' . __('Generated', 'wp-deep-diagnostics') . ': ' . ($meta['timestamp'] ?? ''),
            '- ' . __('Elapsed', 'wp-deep-diagnostics') . ': ' . ($meta['elapsed_ms'] ?? 0) . ' ms',
            '- ' . __('PHP', 'wp-deep-diagnostics') . ': ' . ($meta['php_version'] ?? ''),
            '- ' . __('Context', 'wp-deep-diagnostics') . ': ' . wp_json_encode($meta['context'] ?? []),
            '- ' . __('Top bottleneck', 'wp-deep-diagnostics') . ': ' . ($top['name'] ?? 'n/a'),
            '',
            '## ' . __('Recommendations', 'wp-deep-diagnostics'),
        ];

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
