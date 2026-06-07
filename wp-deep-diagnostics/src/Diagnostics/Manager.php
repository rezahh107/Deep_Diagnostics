<?php
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
            add_action(
                $hook,
                function() use ($hook): void {
                    $this->events->record($hook);
                },
                1
            );
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
