<?php
declare(strict_types=1);

namespace WDDTF\Diagnostics;

use WDDTF\Collectors\AssetAnalyzer;
use WDDTF\Collectors\EventCollector;
use WDDTF\Collectors\HttpCollector;
use WDDTF\Collectors\QueryCollector;
use WDDTF\Collectors\SystemInspector;
use WDDTF\Cron\CronDiagnostics;
use WDDTF\Gravity\GravityDiagnostics;
use WDDTF\Logging\File_Logger;
use WDDTF\Privacy\Redactor;
use WDDTF\Providers\ProviderEvidenceService;
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
    private CronDiagnostics $cron;
    private GravityDiagnostics $gravity;
    private ProviderEvidenceService $providers;
    private bool $finalized = false;

    public function __construct(
        ?CronDiagnostics $cron = null,
        ?GravityDiagnostics $gravity = null,
        ?ProviderEvidenceService $providers = null
    ) {
        $this->cron = $cron ?? new CronDiagnostics();
        $this->gravity = $gravity ?? new GravityDiagnostics();
        $this->providers = $providers ?? new ProviderEvidenceService();
    }

    public function boot(): void {
        $this->startedAt     = microtime(true);
        $this->startedMemory = memory_get_usage(true);
        $this->events        = new EventCollector();
        $this->http          = new HttpCollector();
        $this->queries       = new QueryCollector();
        $this->assets        = new AssetAnalyzer();
        $this->system        = new SystemInspector();

        $this->cron->register();
        $this->gravity->register();

        // Manager boots from the plugin's plugins_loaded callback. Earlier lifecycle hooks
        // cannot be observed truthfully from a normal plugin, so mark our own observation
        // start instead of registering callbacks for hooks that have already fired.
        $this->events->recordCustom('diagnostics_boot', ['phase' => 'plugins_loaded']);

        $hooks = [
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

    public function startCronQualification(): array {
        return $this->cron->startQualification();
    }

    public function getCronDiagnostics(): array {
        return ( new Redactor() )->redact($this->cron->snapshot());
    }

    public function startGravityDiagnostic(): array {
        return $this->gravity->startDiagnostic();
    }

    public function startGravityFlowInboxObservation(): array {
        return $this->startGravityDiagnostic();
    }

    public function getGravityDiagnostics(): array {
        $gravity = ( new Redactor() )->redact($this->gravity->snapshot());
        return $this->presentGravityHostVersions($gravity);
    }

    public function importGppSupportBundle(string $raw): array {
        return $this->providers->importGppBundle($raw);
    }

    public function refreshDiagnosticProvider(string $providerKey): array {
        return $this->providers->captureDirect($providerKey);
    }

    public function getProviderDiagnostics(string $providerKey = 'gpp'): array {
        return $this->providers->diagnostics($providerKey, $this->getLastReport());
    }

    public function exportProviderEvidence(string $providerKey = 'gpp'): ?string {
        return $this->providers->exportJson($providerKey, $this->getLastReport());
    }

    public function finalize(): void {
        if ( $this->finalized ) {
            return;
        }
        if ( wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_CRON') && DOING_CRON) ) {
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
        $cron      = $this->cron->snapshot();
        $gravity   = $this->gravity->snapshot();
        $elapsed   = (microtime(true) - $this->startedAt) * 1000;

        $snapshot = [
            'meta' => [
                'version' => WDDTF_VERSION,
                'timestamp' => gmdate('c'),
                'elapsed_ms' => round($elapsed, 2),
                'memory_peak' => memory_get_peak_usage(true),
                'memory_delta' => memory_get_peak_usage(true) - $this->startedMemory,
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'admin' => is_admin(),
                'context' => [
                    'is_ajax' => wp_doing_ajax(),
                    'is_rest' => defined('REST_REQUEST') && REST_REQUEST,
                    'is_cron' => defined('DOING_CRON') && DOING_CRON,
                ],
                'theme' => Env::activeTheme(),
                'opcache' => Env::opcache(),
            ],
            'timeline' => $timeline,
            'http_requests' => $httpData,
            'queries' => $queryData,
            'assets' => $assetData,
            'system' => $system,
            'cron' => $cron,
            'gravity' => $gravity,
        ];

        // Provider evidence is deliberately not polled here. It is acquired only through
        // explicit admin actions and persisted through its own strict allowlist + Redactor.
        $snapshot = ( new Redactor() )->redact($snapshot);
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

    private function presentGravityHostVersions(array $gravity): array {
        foreach ( ['gravity_forms', 'gravity_flow'] as $host ) {
            $version = $gravity['hosts'][$host]['version'] ?? null;
            if ( ! is_string($version) ) continue;
            if ( 1 === preg_match('/^v([0-9]+(?:\.[0-9A-Za-z-]+)+)$/', $version, $matches) ) {
                $gravity['hosts'][$host]['version'] = $matches[1];
            }
        }
        return $gravity;
    }
}
