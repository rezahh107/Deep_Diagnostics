#!/usr/bin/env python3
"""
WP Deep Diagnostics Plugin Builder
Creates a complete, fixed plugin directory structure with all source files.
Windows compatible. Uses only Python standard library.
"""

import os
import sys

# Plugin base directory
PLUGIN_DIR = "wp-deep-diagnostics"

# All plugin files content
FILES = {
    "wp-deep-diagnostics.php": r'''<?php
/**
 * Plugin Name: WP Deep Diagnostics
 * Version: 1.5.9
 * Requires PHP: 8.1
 * Description: Forensic admin latency telemetry. LLM-ready.
 * Text Domain: wp-deep-diagnostics
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( version_compare(PHP_VERSION, '8.1', '<') ) {
    add_action(
        'admin_notices',
        static function(): void {
            echo '<div class="notice notice-error"><p>' .
                esc_html__('WP Deep Diagnostics requires PHP 8.1+.', 'wp-deep-diagnostics') .
                '</p></div>';
        }
    );
    return;
}

define('WDDTF_VERSION', '1.5.9');
define('WDDTF_FILE', __FILE__);
define('WDDTF_PATH', plugin_dir_path(__FILE__));
define('WDDTF_URL', plugin_dir_url(__FILE__));
define('WDDTF_BASENAME', plugin_basename(__FILE__));

$autoload = WDDTF_PATH . 'vendor/autoload.php';

if ( file_exists($autoload) ) {
    require_once $autoload;
} else {
    require_once WDDTF_PATH . 'src/Autoloader.php';
    \WDDTF\Autoloader::register('WDDTF', WDDTF_PATH . 'src');
}

add_action(
    'plugins_loaded',
    static function(): void {
        load_plugin_textdomain('wp-deep-diagnostics', false, dirname(WDDTF_BASENAME) . '/languages');
        ( new \WDDTF\Plugin() )->boot();
    }
);
''',

    "src/Plugin.php": r'''<?php
declare(strict_types=1);

namespace WDDTF;

use WDDTF\Admin\Admin_Page;
use WDDTF\Admin\Ajax_Controller;
use WDDTF\Diagnostics\Manager;
use WDDTF\Frontend\Frontend;
use WDDTF\Rest\Routes;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Plugin {
    private Manager $manager;

    public function boot(): void {
        $this->manager = new Manager();
        $this->manager->boot();

        add_action(
            'init',
            function(): void {
                ( new Admin_Page($this->manager) )->register();
                ( new Ajax_Controller($this->manager) )->register();
                ( new Routes($this->manager) )->register();
                ( new Frontend($this->manager) )->register();
            }
        );

        add_action('shutdown', [$this->manager, 'finalize'], 9999);

        register_shutdown_function(
            function(): void {
                if ( isset($this->manager) && ! $this->manager->isFinalized() ) {
                    $this->manager->finalize();
                }
            }
        );
    }
}
''',

    "src/Autoloader.php": r'''<?php
declare(strict_types=1);

namespace WDDTF;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Autoloader {
    public static function register(string $prefix, string $base): void {
        spl_autoload_register(
            static function(string $class) use ($prefix, $base): void {
                $len = strlen($prefix);

                if ( 0 !== strncmp($prefix, $class, $len) ) {
                    return;
                }

                $relative = substr($class, $len);
                $file     = rtrim($base, '/\\') . '/' . str_replace('\\', '/', $relative) . '.php';

                if ( file_exists($file) ) {
                    require_once $file;
                }
            }
        );
    }
}
''',

    "src/Diagnostics/Manager.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Diagnostics;

use WDDTF\Collectors\AssetAnalyzer;
use WDDTF\Collectors\EventCollector;
use WDDTF\Collectors\HttpCollector;
use WDDTF\Collectors\QueryCollector;
use WDDTF\Collectors\SystemInspector;
use WDDTF\Logging\File_Logger;
use WDDTF\Support\Env;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Manager {
    private float $startedAt;
    private int $startedMemory;
    private EventCollector $events;
    private HttpCollector $http;
    private QueryCollector $queries;
    private AssetAnalyzer $assets;
    private SystemInspector $system;
    private bool $finalized = false;

    public function boot(): void {
        $this->startedAt     = microtime(true);
        $this->startedMemory = memory_get_usage(true);
        $this->events        = new EventCollector();
        $this->http          = new HttpCollector();
        $this->queries       = new QueryCollector();
        $this->assets        = new AssetAnalyzer();
        $this->system        = new SystemInspector();

        $hooks = [
            'muplugins_loaded',
            'plugins_loaded',
            'after_setup_theme',
            'init',
            'wp_loaded',
            'admin_init',
            'current_screen',
            'admin_enqueue_scripts',
        ];

        foreach ( $hooks as $hook ) {
            add_action($hook, fn(): void => $this->events->record($hook), 1);
        }

        add_filter(
            'pre_http_request',
            function(mixed $pre, array $args, string $url): mixed {
                $this->http->pre($url, $args);
                return $pre;
            },
            10,
            3
        );

        add_action(
            'http_api_debug',
            function(mixed $response, string $context, mixed $class, array $args, string $url): void {
                if ( 'response' === $context ) {
                    $this->http->debug($response, $args, $url);
                }
            },
            10,
            5
        );
    }

    public function isFinalized(): bool {
        return $this->finalized;
    }

    public function recordEvent(string $layer, array $data): void {
        if ( isset($this->events) ) {
            $this->events->recordCustom($layer, $data);
        }
    }

    public function finalize(): void {
        if ( $this->finalized ) {
            return;
        }

        if (
            wp_doing_ajax() ||
            ( defined('REST_REQUEST') && REST_REQUEST ) ||
            ( defined('DOING_CRON') && DOING_CRON )
        ) {
            return;
        }

        if ( ! function_exists('set_transient') || ! function_exists('wp_upload_dir') ) {
            return;
        }

        global $wpdb;

        if ( ! isset($wpdb) || ! $wpdb instanceof \wpdb ) {
            return;
        }

        $this->finalized = true;

        $timeline  = $this->events->snapshot();
        $httpData  = $this->http->snapshot();
        $queryData = $this->queries->snapshot($wpdb);
        $assetData = $this->assets->snapshot();
        $system    = $this->system->snapshot();
        $elapsed   = (microtime(true) - $this->startedAt) * 1000;

        $snapshot = [
            'meta'          => [
                'version'      => WDDTF_VERSION,
                'timestamp'    => gmdate('c'),
                'elapsed_ms'   => round($elapsed, 2),
                'memory_peak'  => memory_get_peak_usage(true),
                'memory_delta' => memory_get_peak_usage(true) - $this->startedMemory,
                'php_version'  => PHP_VERSION,
                'wp_version'   => get_bloginfo('version'),
                'admin'        => is_admin(),
                'context'      => [
                    'is_ajax' => wp_doing_ajax(),
                    'is_rest' => defined('REST_REQUEST') && REST_REQUEST,
                    'is_cron' => defined('DOING_CRON') && DOING_CRON,
                ],
                'theme'        => Env::activeTheme(),
                'opcache'      => Env::opcache(),
            ],
            'timeline'      => $timeline,
            'http_requests' => $httpData,
            'queries'       => $queryData,
            'assets'        => $assetData,
            'system'        => $system,
        ];

        $analyzer = new DiagnosticsAnalyzer();
        $report   = $analyzer->analyze($snapshot);
        $logger   = new File_Logger();

        $jsonPath = $logger->saveJson($report);
        $mdPath   = $logger->saveMarkdown(( new Report_Builder() )->toMarkdown($report));

        set_transient('wddtf_last_report', $report, 12 * HOUR_IN_SECONDS);
        set_transient('wddtf_last_report_json', $jsonPath, 12 * HOUR_IN_SECONDS);
        set_transient('wddtf_last_report_md', $mdPath, 12 * HOUR_IN_SECONDS);
    }

    public function getLastReport(): array {
        $report = get_transient('wddtf_last_report');

        return is_array($report) ? $report : [];
    }
}
''',

    "src/Diagnostics/DiagnosticsAnalyzer.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Diagnostics;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class DiagnosticsAnalyzer {
    public function analyze(array $snapshot): array {
        $httpCount    = count($snapshot['http_requests'] ?? []);
        $autoloadSize = $snapshot['system']['autoload_size'] ?? 0;
        $totalAssets  = $snapshot['assets']['total_enqueued'] ?? 0;

        $recommendations = [];

        if ( $httpCount > 0 ) {
            $recommendations[] = __('External HTTP calls detected – likely the main bottleneck when internet is restricted.', 'wp-deep-diagnostics');
        }

        if ( $autoloadSize > 1048576 ) {
            $recommendations[] = __('Autoloaded options exceed 1 MB.', 'wp-deep-diagnostics');
        }

        if ( $totalAssets > 30 ) {
            $recommendations[] = sprintf(
                __('Many admin assets enqueued (%d).', 'wp-deep-diagnostics'),
                $totalAssets
            );
        }

        if ( empty($recommendations) ) {
            $recommendations[] = __('No obvious bottleneck detected. Enable SAVEQUERIES and retry.', 'wp-deep-diagnostics');
        }

        $phases   = [];
        $timeline = $snapshot['timeline'] ?? [];

        foreach ( $timeline as $event ) {
            if ( isset($event['data']['elapsed_since_prev']) ) {
                $phases[] = [
                    'layer'   => $event['layer'],
                    'elapsed' => $event['data']['elapsed_since_prev'],
                ];
            }
        }

        usort(
            $phases,
            static fn(array $left, array $right): int => $right['elapsed'] <=> $left['elapsed']
        );

        $http = $snapshot['http_requests'] ?? [];

        usort(
            $http,
            static fn(array $left, array $right): int => ($right['duration'] ?? 0) <=> ($left['duration'] ?? 0)
        );

        $autoloadScore = 10;

        if ( $autoloadSize > 2000000 ) {
            $autoloadScore = 95;
        } elseif ( $autoloadSize > 1000000 ) {
            $autoloadScore = 70;
        } elseif ( $autoloadSize > 500000 ) {
            $autoloadScore = 40;
        }

        $assetScore = 10;

        if ( $totalAssets > 60 ) {
            $assetScore = 90;
        } elseif ( $totalAssets > 40 ) {
            $assetScore = 60;
        } elseif ( $totalAssets > 20 ) {
            $assetScore = 30;
        }

        return [
            'meta'            => $snapshot['meta'],
            'layers'          => [
                'http'     => [
                    'count'    => $httpCount,
                    'blocking' => array_values(array_filter($http, static fn(array $request): bool => ! empty($request['blocking']))),
                ],
                'database' => $snapshot['queries'] ?? [],
                'cache'    => [
                    'autoload_bytes' => $autoloadSize,
                    'heavy'          => $snapshot['system']['heavy_autoload'] ?? [],
                ],
                'assets'   => $snapshot['assets']['heavy'] ?? [],
            ],
            'bottlenecks'     => [
                [
                    'name'  => __('External HTTP', 'wp-deep-diagnostics'),
                    'score' => $httpCount ? 92 : 10,
                ],
                [
                    'name'  => __('Autoload Options', 'wp-deep-diagnostics'),
                    'score' => $autoloadScore,
                ],
                [
                    'name'  => __('Admin Assets', 'wp-deep-diagnostics'),
                    'score' => $assetScore,
                ],
            ],
            'recommendations' => $recommendations,
            'top_offenders'   => [
                'slowest_phases'  => array_slice($phases, 0, 5),
                'slowest_http'    => array_slice($http, 0, 5),
                'slowest_queries' => $snapshot['queries']['queries'] ?? [],
                'heaviest_assets' => $snapshot['assets']['heavy'] ?? [],
            ],
            'llm_bundle'       => $snapshot,
        ];
    }
}
''',

    "src/Diagnostics/Report_Builder.php": r'''<?php
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
''',

    "src/Collectors/EventCollector.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Collectors;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class EventCollector {
    private array $events = [];
    private float $lastTime = 0.0;
    private array $hookCounts = [];
    private const MAX = 200;

    public function record(string $hook): void {
        $now = microtime(true);

        $this->hookCounts[$hook] = ($this->hookCounts[$hook] ?? 0) + 1;

        if ( $this->lastTime > 0 ) {
            $elapsed = $now - $this->lastTime;
            $index   = count($this->events) - 1;

            if ( $index >= 0 ) {
                $this->events[$index]['data']['elapsed_since_prev'] = $elapsed;
            }
        }

        if ( count($this->events) >= self::MAX ) {
            array_shift($this->events);
        }

        $this->events[] = [
            'layer' => $hook,
            'data'  => [
                'did_action' => $this->hookCounts[$hook],
                'memory'     => memory_get_usage(true),
                'time'       => $now,
            ],
        ];

        $this->lastTime = $now;
    }

    public function recordCustom(string $layer, array $data): void {
        $now = microtime(true);

        if ( $this->lastTime > 0 ) {
            $data['elapsed_since_prev'] = $now - $this->lastTime;
        }

        if ( count($this->events) >= self::MAX ) {
            array_shift($this->events);
        }

        $this->events[] = [
            'layer' => $layer,
            'data'  => $data + ['time' => $now],
        ];

        $this->lastTime = $now;
    }

    public function snapshot(): array {
        return $this->events;
    }
}
''',

    "src/Collectors/HttpCollector.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Collectors;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class HttpCollector {
    private array $requests = [];
    private array $pending = [];
    private const MAX = 200;

    public function pre(string $url, array $args): void {
        $key = $this->fingerprint($url, $args);

        $this->pending[$key] = [
            'start' => microtime(true),
            'url'   => $url,
            'args'  => [
                'method'   => isset($args['method']) ? sanitize_text_field((string) $args['method']) : 'GET',
                'blocking' => isset($args['blocking']) ? (bool) $args['blocking'] : true,
                'timeout'  => isset($args['timeout']) ? (float) $args['timeout'] : 5.0,
                'headers'  => isset($args['headers']) ? array_keys((array) $args['headers']) : [],
            ],
        ];
    }

    public function debug(mixed $response, array $args, string $url): void {
        $key = $this->fingerprint($url, $args);

        if ( ! isset($this->pending[$key]) ) {
            return;
        }

        $info     = $this->pending[$key]['args'];
        $start    = $this->pending[$key]['start'];
        $duration = microtime(true) - $start;

        unset($this->pending[$key]);

        if ( count($this->requests) >= self::MAX ) {
            array_shift($this->requests);
        }

        $this->requests[] = [
            'url'      => $url,
            'duration' => $duration,
            'blocking' => $info['blocking'] ?? true,
            'result'   => is_wp_error($response) ? $response->get_error_message() : (is_array($response) ? array_keys($response) : gettype($response)),
            'args'     => $info,
        ];
    }

    private function fingerprint(string $url, array $args): string {
        $data = [
            'url'      => $url,
            'method'   => $args['method'] ?? 'GET',
            'blocking' => $args['blocking'] ?? true,
            'timeout'  => $args['timeout'] ?? 5,
            'headers'  => isset($args['headers']) ? (array) $args['headers'] : [],
            'body'     => $args['body'] ?? null,
            'httpversion' => $args['httpversion'] ?? '1.0',
        ];

        return md5((string) wp_json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_SORT_KEYS));
    }

    public function snapshot(): array {
        return $this->requests;
    }
}
''',

    "src/Collectors/QueryCollector.php": r'''<?php
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
''',

    "src/Collectors/AssetAnalyzer.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Collectors;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class AssetAnalyzer {
    public function snapshot(): array {
        if ( ! is_admin() ) {
            return [
                'total_enqueued' => 0,
                'heavy'          => [],
            ];
        }

        global $wp_scripts, $wp_styles;

        $heavy          = [];
        $totalEnqueued  = 0;
        $dependenciesMap = [
            'scripts' => $wp_scripts,
            'styles'  => $wp_styles,
        ];

        foreach ( $dependenciesMap as $type => $dependencies ) {
            if ( ! $dependencies instanceof \WP_Dependencies ) {
                continue;
            }

            $queue          = $dependencies->queue ?? [];
            $totalEnqueued += count($queue);

            foreach ( $queue as $handle ) {
                if ( ! isset($dependencies->registered[$handle]) ) {
                    continue;
                }

                $item = $dependencies->registered[$handle];

                if ( empty($item->src) ) {
                    continue;
                }

                if ( wp_parse_url($item->src, PHP_URL_HOST) ) {
                    continue;
                }

                $path = $this->localPath($item->src);

                if ( $path && file_exists($path) ) {
                    $heavy[] = [
                        'type'   => $type,
                        'handle' => $handle,
                        'src'    => $item->src,
                        'size'   => filesize($path),
                    ];
                }
            }
        }

        usort(
            $heavy,
            static fn(array $left, array $right): int => $right['size'] <=> $left['size']
        );

        return [
            'total_enqueued' => $totalEnqueued,
            'heavy'          => array_slice($heavy, 0, 10),
        ];
    }

    private function localPath(string $src): ?string {
        $contentUrl = content_url();
        $contentDir = WP_CONTENT_DIR;

        $src = strtok($src, '?#');

        if ( 0 === strpos($src, $contentUrl) ) {
            return $contentDir . substr($src, strlen($contentUrl));
        }

        $siteUrl = site_url();

        if ( 0 === strpos($src, $siteUrl) ) {
            return ABSPATH . substr($src, strlen($siteUrl));
        }

        return null;
    }
}
''',

    "src/Collectors/SystemInspector.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Collectors;

use WDDTF\Support\Env;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class SystemInspector {
    public function snapshot(): array {
        $data = [
            'php_version'        => PHP_VERSION,
            'wp_version'         => get_bloginfo('version'),
            'memory_limit'       => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'opcache'            => Env::opcache(),
            'autoload_count'     => 0,
            'autoload_size'      => 0,
            'heavy_autoload'     => [],
        ];

        $options = wp_load_alloptions();

        if ( is_array($options) ) {
            $data['autoload_count'] = count($options);

            foreach ( $options as $value ) {
                $data['autoload_size'] += strlen(maybe_serialize($value));
            }
        }

        global $wpdb;

        if ( isset($wpdb) && $wpdb instanceof \wpdb ) {
            $heavy = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload = %s ORDER BY size DESC LIMIT 10",
                    'yes'
                ),
                ARRAY_A
            );

            $data['heavy_autoload'] = is_array($heavy) ? $heavy : [];
        }

        return $data;
    }
}
''',

    "src/Admin/Admin_Page.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Admin;

use WDDTF\Diagnostics\Manager;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Admin_Page {
    public function __construct(private Manager $manager) {
    }

    public function register(): void {
        add_action(
            'admin_menu',
            function(): void {
                add_management_page(
                    __('Deep Diagnostics', 'wp-deep-diagnostics'),
                    __('Deep Diagnostics', 'wp-deep-diagnostics'),
                    'manage_options',
                    'wp-deep-diagnostics',
                    [$this, 'render']
                );
            }
        );

        add_action(
            'admin_enqueue_scripts',
            function(string $hookSuffix): void {
                if ( 'tools_page_wp-deep-diagnostics' !== $hookSuffix ) {
                    return;
                }

                wp_enqueue_style(
                    'wddtf-admin',
                    WDDTF_URL . 'assets/admin.css',
                    [],
                    WDDTF_VERSION
                );
            }
        );
    }

    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('Access denied', 'wp-deep-diagnostics'));
        }

        $report = $this->manager->getLastReport();

        require WDDTF_PATH . 'templates/admin-page.php';
    }
}
''',

    "src/Admin/Ajax_Controller.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Admin;

use WDDTF\Diagnostics\Manager;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Ajax_Controller {
    public function __construct(private Manager $manager) {
    }

    public function register(): void {
        add_action(
            'wp_ajax_wddtf_get_report',
            function(): void {
                if ( ! current_user_can('manage_options') ) {
                    wp_send_json_error('forbidden', 403);
                }

                check_ajax_referer('wddtf_nonce', 'nonce');
                wp_send_json_success($this->manager->getLastReport());
            }
        );
    }
}
''',

    "src/Rest/Routes.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Rest;

use WDDTF\Diagnostics\Manager;
use WDDTF\Support\Input;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Routes {
    public function __construct(private Manager $manager) {
    }

    public function register(): void {
        add_action(
            'rest_api_init',
            function(): void {
                register_rest_route(
                    'wp-deep-diagnostics/v1',
                    '/report',
                    [
                        'methods'             => 'GET',
                        'permission_callback' => function(): bool {
                            $capability = apply_filters('wddtf_rest_capability', 'manage_options');
                            return current_user_can($capability);
                        },
                        'callback'            => function(\WP_REST_Request $request) {
                            $ip  = Input::remoteAddr('unknown');
                            $key = 'wddtf_rate_' . Input::hashedKeyFragment($ip);

                            if ( get_transient($key) ) {
                                return new \WP_Error(
                                    'rate_limited',
                                    __('Too many requests. Wait a moment.', 'wp-deep-diagnostics'),
                                    ['status' => 429]
                                );
                            }

                            set_transient($key, 1, 5);

                            return $this->manager->getLastReport();
                        },
                    ]
                );
            }
        );
    }
}
''',

    "src/Frontend/Frontend.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Frontend;

use WDDTF\Diagnostics\Manager;
use WDDTF\Support\Input;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Frontend {
    public function __construct(private Manager $manager) {
    }

    public function register(): void {
        add_action(
            'template_redirect',
            function(): void {
                if ( is_admin() || wp_doing_ajax() || ( defined('REST_REQUEST') && REST_REQUEST ) ) {
                    return;
                }

                $ip  = Input::remoteAddr('');
                $key = 'wddtf_frontend_sampled_' . Input::hashedKeyFragment($ip);

                if ( ! get_transient($key) ) {
                    $this->manager->recordEvent(
                        'frontend',
                        [
                            'uri' => Input::requestUri(''),
                        ]
                    );

                    set_transient($key, 1, 300);
                }
            }
        );
    }
}
''',

    "src/Logging/File_Logger.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Logging;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class File_Logger {
    public function dir(): string {
        $upload = wp_upload_dir();
        $base   = trailingslashit($upload['basedir']) . 'wp-deep-diagnostics/';

        if ( ! file_exists($base) ) {
            wp_mkdir_p($base);
            file_put_contents($base . 'index.php', '<?php // silence');
            file_put_contents($base . '.htaccess', 'Deny from all');
        }

        return $base;
    }

    public function saveJson(array $payload): string {
        $path   = $this->dir() . $this->buildFilename('json');
        $result = file_put_contents(
            $path,
            wp_json_encode(
                $payload,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
            ),
            LOCK_EX
        );

        if ( false === $result ) {
            error_log('WDDTF: Failed to write JSON report to ' . $path);
            return '';
        }

        return $path;
    }

    public function saveMarkdown(string $markdown): string {
        $path   = $this->dir() . $this->buildFilename('md');
        $result = file_put_contents($path, $markdown, LOCK_EX);

        if ( false === $result ) {
            error_log('WDDTF: Failed to write Markdown report to ' . $path);
            return '';
        }

        return $path;
    }

    private function buildFilename(string $extension): string {
        $microtime = microtime(true);
        $seconds   = gmdate('Y-m-d-His', (int) $microtime);
        $micros    = sprintf('%06d', (int) (($microtime - (int) $microtime) * 1000000));
        $random    = wp_generate_password(6, false, false);

        return sprintf('report-%1$s-%2$s-%3$s.%4$s', $seconds, $micros, strtolower($random), $extension);
    }
}
''',

    "src/Logging/Severity.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Logging;

if ( ! defined('ABSPATH') ) {
    exit;
}

enum Severity: string {
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
''',

    "src/Logging/Log_Entry.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Logging;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Log_Entry {
    public function __construct(
        public string $layer,
        public string $key,
        public mixed $value,
        public Severity $severity,
        public float $timestamp,
    ) {
    }
}
''',

    "src/Support/Input.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Support;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Input {
    public static function remoteAddr(string $default = 'unknown'): string {
        $raw = $_SERVER['REMOTE_ADDR'] ?? $default;

        if ( ! is_string($raw) || '' === $raw ) {
            $raw = $default;
        }

        return sanitize_text_field(wp_unslash($raw));
    }

    public static function requestUri(string $default = ''): string {
        $raw = $_SERVER['REQUEST_URI'] ?? $default;

        if ( ! is_string($raw) ) {
            $raw = $default;
        }

        return sanitize_text_field(wp_unslash($raw));
    }

    public static function hashedKeyFragment(string $value): string {
        return md5($value);
    }
}
''',

    "src/Support/Env.php": r'''<?php
declare(strict_types=1);

namespace WDDTF\Support;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Env {
    public static function bytesToHuman(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size  = (float) $bytes;
        $unit  = 0;

        while ( $size >= 1024 && $unit < 3 ) {
            $size /= 1024;
            ++$unit;
        }

        return rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.') . ' ' . $units[$unit];
    }

    public static function activeTheme(): array {
        $theme = wp_get_theme();

        return [
            'name'       => $theme->get('Name'),
            'template'   => $theme->get_template(),
            'stylesheet' => $theme->get_stylesheet(),
            'version'    => $theme->get('Version'),
        ];
    }

    public static function opcache(): array {
        if ( ! function_exists('opcache_get_status') ) {
            return ['enabled' => false];
        }

        $status = @opcache_get_status(false);

        if ( ! is_array($status) ) {
            return ['enabled' => false];
        }

        return [
            'enabled'    => true,
            'hit_rate'   => $status['opcache_statistics']['opcache_hit_rate'] ?? null,
            'mem_used'   => $status['memory_usage']['used_memory'] ?? null,
            'mem_free'   => $status['memory_usage']['free_memory'] ?? null,
            'cache_full' => ! empty($status['opcache_statistics']['cache_full']),
            'num_cached' => $status['opcache_statistics']['num_cached_scripts'] ?? null,
        ];
    }
}
''',

    "templates/admin-page.php": r'''<?php
if ( ! defined('ABSPATH') ) {
    exit;
}
?>
<div class="wrap wddtf-wrap">
    <h1><?php esc_html_e('WP Deep Diagnostics', 'wp-deep-diagnostics'); ?></h1>

    <?php if ( empty($report) ) : ?>
        <p><?php esc_html_e('No report yet. Load any admin page and refresh.', 'wp-deep-diagnostics'); ?></p>
    <?php else : ?>
        <?php
        $queries_warning = $report['layers']['database']['warning'] ?? '';
        if ( $queries_warning ) :
            ?>
            <div class="notice notice-warning inline" style="margin:12px 0;">
                <p>
                    <?php echo esc_html($queries_warning); ?>
                    <?php esc_html_e(' Add define( "SAVEQUERIES", true ) to wp-config.php to enable precise query timing.', 'wp-deep-diagnostics'); ?>
                </p>
            </div>
        <?php endif; ?>

        <p>
            <?php esc_html_e('Top bottleneck', 'wp-deep-diagnostics'); ?>:
            <?php echo esc_html($report['bottlenecks'][0]['name'] ?? 'n/a'); ?>
        </p>

        <textarea readonly rows="20" class="large-text code" style="font-family:monospace;"><?php
            echo esc_textarea(
                wp_json_encode(
                    $report['llm_bundle'] ?? [],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
        ?></textarea>
    <?php endif; ?>
</div>
''',

    "uninstall.php": r'''<?php
declare(strict_types=1);

if ( ! defined('WP_UNINSTALL_PLUGIN') ) {
    exit;
}

delete_transient('wddtf_last_report');
delete_transient('wddtf_last_report_json');
delete_transient('wddtf_last_report_md');

$upload = wp_upload_dir();
$dir    = trailingslashit($upload['basedir']) . 'wp-deep-diagnostics/';

if ( is_dir($dir) ) {
    foreach ( glob($dir . '*') as $file ) {
        if ( is_file($file) ) {
            @unlink($file);
        }
    }

    @rmdir($dir);
}
''',

    "assets/admin.css": r'''.wddtf-wrap textarea.code{font-family:monospace;}
''',

    "languages/.gitkeep": "",
}


def create_directory(path: str) -> None:
    """Create directory if it doesn't exist."""
    if not os.path.exists(path):
        os.makedirs(path)
        print(f"  Created directory: {path}")


def write_file(filepath: str, content: str) -> None:
    """Write content to file with UTF-8 encoding."""
    # Ensure parent directory exists
    parent_dir = os.path.dirname(filepath)
    if parent_dir and not os.path.exists(parent_dir):
        create_directory(parent_dir)
    
    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(content)
    print(f"  Written: {filepath}")


def main() -> None:
    """Main entry point."""
    print("=" * 60)
    print("WP Deep Diagnostics Plugin Builder")
    print("=" * 60)
    print()
    
    # Create plugin base directory
    print(f"Creating plugin directory: {PLUGIN_DIR}")
    create_directory(PLUGIN_DIR)
    
    # Create subdirectories
    subdirs = ['src', 'src/Admin', 'src/Collectors', 'src/Diagnostics', 
               'src/Frontend', 'src/Logging', 'src/Rest', 'src/Support',
               'templates', 'assets', 'languages']
    
    for subdir in subdirs:
        create_directory(os.path.join(PLUGIN_DIR, subdir))
    
    print()
    print("Writing plugin files...")
    print("-" * 40)
    
    # Write all files
    for filepath, content in FILES.items():
        full_path = os.path.join(PLUGIN_DIR, filepath)
        write_file(full_path, content)
    
    print()
    print("-" * 40)
    print(f"Plugin created successfully in: {os.path.abspath(PLUGIN_DIR)}")
    print()
    print("The plugin is now ready to be:")
    print("  1. Zipped for distribution")
    print("  2. Installed in WordPress")
    print()
    print("To create a ZIP file, run:")
    print(f"  cd {PLUGIN_DIR} && zip -r ../wp-deep-diagnostics.zip .")
    print("=" * 60)


if __name__ == '__main__':
    main()
