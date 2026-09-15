<?php
/**
 * Test-only request probe for Deep Diagnostics overhead qualification.
 *
 * Loaded as a must-use plugin by the disposable benchmark runtime. It is not
 * packaged with the production plugin.
 */
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

$GLOBALS['wddtf_benchmark_probe'] = [
    'plugins_loaded_before_ns' => null,
    'plugins_loaded_window_ms' => null,
    'shutdown_before_ns'       => null,
];

add_action(
    'plugins_loaded',
    static function(): void {
        $GLOBALS['wddtf_benchmark_probe']['plugins_loaded_before_ns'] = hrtime(true);
    },
    9
);

add_action(
    'plugins_loaded',
    static function(): void {
        $start = $GLOBALS['wddtf_benchmark_probe']['plugins_loaded_before_ns'] ?? null;
        if ( is_int($start) ) {
            $GLOBALS['wddtf_benchmark_probe']['plugins_loaded_window_ms'] = (hrtime(true) - $start) / 1_000_000;
        }
    },
    11
);

add_action(
    'wp_loaded',
    static function(): void {
        $scenario = isset($_GET['wddtf_bench_scenario'])
            ? sanitize_key(wp_unslash((string) $_GET['wddtf_bench_scenario']))
            : '';

        if ( 'diagnostic_workload' !== $scenario ) {
            return;
        }

        global $wpdb;

        if ( isset($wpdb) && $wpdb instanceof wpdb ) {
            $wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'publish'");
            $wpdb->get_var("SELECT COUNT(option_id) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')");
            $wpdb->get_results("SELECT ID, post_type FROM {$wpdb->posts} ORDER BY ID ASC LIMIT 20", ARRAY_A);
        }

        $fixtureUrl = get_option('wddtf_benchmark_http_url');
        if ( is_string($fixtureUrl) && '' !== $fixtureUrl ) {
            for ( $i = 0; $i < 3; $i++ ) {
                wp_remote_get(
                    add_query_arg('sample', (string) $i, $fixtureUrl),
                    [
                        'timeout'   => 2,
                        'blocking'  => true,
                        'sslverify' => false,
                    ]
                );
            }
        }
    },
    20
);

add_action(
    'shutdown',
    static function(): void {
        $GLOBALS['wddtf_benchmark_probe']['shutdown_before_ns'] = hrtime(true);
    },
    9998
);

add_action(
    'shutdown',
    static function(): void {
        if ( empty($_GET['wddtf_bench']) ) {
            return;
        }

        $token = sanitize_key(wp_unslash((string) $_GET['wddtf_bench']));
        if ( '' === $token ) {
            return;
        }

        $scenario = isset($_GET['wddtf_bench_scenario'])
            ? sanitize_key(wp_unslash((string) $_GET['wddtf_bench_scenario']))
            : 'unknown';

        $shutdownStart = $GLOBALS['wddtf_benchmark_probe']['shutdown_before_ns'] ?? null;
        $shutdownMs = is_int($shutdownStart)
            ? (hrtime(true) - $shutdownStart) / 1_000_000
            : null;

        $record = [
            'token'                    => $token,
            'scenario'                 => $scenario,
            'deep_active'              => defined('WDDTF_VERSION'),
            'peak_memory_bytes'        => memory_get_peak_usage(true),
            'memory_usage_bytes'       => memory_get_usage(true),
            'query_count'              => function_exists('get_num_queries') ? get_num_queries() : null,
            'plugins_loaded_window_ms' => $GLOBALS['wddtf_benchmark_probe']['plugins_loaded_window_ms'] ?? null,
            'shutdown_window_ms'       => $shutdownMs,
        ];

        $path = getenv('WDDTF_BENCHMARK_METRICS_FILE');
        if ( ! is_string($path) || '' === $path ) {
            return;
        }

        file_put_contents(
            $path,
            wp_json_encode($record, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    },
    PHP_INT_MAX
);
