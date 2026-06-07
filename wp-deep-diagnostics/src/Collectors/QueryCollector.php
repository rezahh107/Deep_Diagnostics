<?php
declare(strict_types=1);

namespace WDDTF\Collectors;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class QueryCollector {
    public function snapshot($wpdb): array {
        if ( defined('SAVEQUERIES') && SAVEQUERIES && isset($wpdb->queries) && is_array($wpdb->queries) ) {
            $slow = [];

            foreach ( $wpdb->queries as $query ) {
                $slow[] = [
                    'sql'   => $query[0] ?? '',
                    'time'  => (float) ($query[1] ?? 0),
                    'stack' => $query[2] ?? '',
                ];
            }

            usort(
                $slow,
                static fn(array $left, array $right): int => $right['time'] <=> $left['time']
            );

            return [
                'queries' => array_slice($slow, 0, 10),
            ];
        }

        return [
            'warning' => __('SAVEQUERIES disabled. Enable for precise query timing.', 'wp-deep-diagnostics'),
        ];
    }
}
