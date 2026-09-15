<?php
/**
 * Plugin Name: WDDTF Performance Benchmark Probe
 * Description: CI/local benchmark-only request probe. Not production plugin code.
 */
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

final class WDDTF_Performance_Benchmark_Probe {
    private ?int $lateShutdownStartedNs = null;

    public function register(): void {
        add_action('admin_menu', [$this, 'registerWorkloadPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueWorkloadAssets']);
        add_action('shutdown', [$this, 'markLateShutdownStart'], 9998);
        add_action('shutdown', [$this, 'persistMeasurement'], PHP_INT_MAX);
    }

    public function registerWorkloadPage(): void {
        add_management_page(
            'WDDTF Performance Fixture',
            'WDDTF Performance Fixture',
            'manage_options',
            'wddtf-performance-fixture',
            [$this, 'renderWorkloadPage']
        );
    }

    public function enqueueWorkloadAssets(string $hookSuffix): void {
        if ( 'tools_page_wddtf-performance-fixture' !== $hookSuffix ) {
            return;
        }

        for ( $index = 0; $index < 8; $index++ ) {
            $handle = 'wddtf-perf-script-' . $index;
            wp_register_script(
                $handle,
                includes_url('js/wp-util.min.js') . '?fixture=' . $index,
                [],
                null,
                true
            );
            wp_enqueue_script($handle);
        }

        for ( $index = 0; $index < 8; $index++ ) {
            $handle = 'wddtf-perf-style-' . $index;
            wp_register_style(
                $handle,
                includes_url('css/dashicons.min.css') . '?fixture=' . $index,
                [],
                null
            );
            wp_enqueue_style($handle);
        }
    }

    public function renderWorkloadPage(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die('Forbidden');
        }

        global $wpdb;

        $optionNames = [
            'blogname',
            'blogdescription',
            'siteurl',
            'home',
            'admin_email',
            'timezone_string',
        ];

        for ( $index = 0; $index < 18; $index++ ) {
            $option = $optionNames[$index % count($optionNames)];
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                    $option
                )
            );
        }

        echo '<div class="wrap"><h1>WDDTF Performance Fixture</h1><p>Deterministic bounded diagnostic workload.</p></div>';
    }

    public function markLateShutdownStart(): void {
        if ( null === $this->sampleId() ) {
            return;
        }

        $this->lateShutdownStartedNs = hrtime(true);
    }

    public function persistMeasurement(): void {
        $sampleId = $this->sampleId();
        if ( null === $sampleId ) {
            return;
        }

        global $wpdb;

        $endedNs = hrtime(true);
        $metrics = [
            'sample_id'          => $sampleId,
            'deep_active'        => defined('WDDTF_VERSION'),
            'peak_memory_bytes'  => memory_get_peak_usage(true),
            'memory_usage_bytes' => memory_get_usage(true),
            'db_query_count'     => isset($wpdb) && $wpdb instanceof wpdb ? (int) $wpdb->num_queries : null,
            'late_shutdown_ms'   => null === $this->lateShutdownStartedNs
                ? null
                : round(($endedNs - $this->lateShutdownStartedNs) / 1_000_000, 3),
        ];

        $outputDir = WP_CONTENT_DIR . '/wddtf-benchmark-output';
        if ( ! is_dir($outputDir) && ! wp_mkdir_p($outputDir) ) {
            error_log('WDDTF benchmark probe could not create output directory.');
            return;
        }

        $encoded = wp_json_encode($metrics, JSON_UNESCAPED_SLASHES);
        if ( ! is_string($encoded) ) {
            error_log('WDDTF benchmark probe could not encode metrics.');
            return;
        }

        $path = $outputDir . '/' . $sampleId . '.json';
        if ( false === file_put_contents($path, $encoded, LOCK_EX) ) {
            error_log('WDDTF benchmark probe could not persist metrics.');
        }
    }

    private function sampleId(): ?string {
        if ( ! isset($_GET['wddtf_benchmark_sample']) || ! is_string($_GET['wddtf_benchmark_sample']) ) {
            return null;
        }

        $sampleId = wp_unslash($_GET['wddtf_benchmark_sample']);
        if ( 1 !== preg_match('/^[a-z0-9-]{1,80}$/', $sampleId) ) {
            return null;
        }

        return $sampleId;
    }
}

(new WDDTF_Performance_Benchmark_Probe())->register();
