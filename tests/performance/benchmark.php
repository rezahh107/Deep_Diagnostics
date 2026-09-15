<?php
declare(strict_types=1);

require_once __DIR__ . '/BenchmarkSummary.php';
require_once __DIR__ . '/CanonicalBaseline.php';

$options = getopt('', [
    'wordpress-dir:',
    'base-url:',
    'samples::',
    'warmups::',
    'output:',
    'summary:',
    'git-sha::',
]);

$wordpressDir = isset($options['wordpress-dir']) ? rtrim((string) $options['wordpress-dir'], '/\\') : '';
$baseUrl      = isset($options['base-url']) ? rtrim((string) $options['base-url'], '/') : '';
$outputPath   = isset($options['output']) ? (string) $options['output'] : '';
$summaryPath  = isset($options['summary']) ? (string) $options['summary'] : '';
$samples      = isset($options['samples']) ? (int) $options['samples'] : 15;
$warmups      = isset($options['warmups']) ? (int) $options['warmups'] : 1;
$gitSha       = isset($options['git-sha']) ? (string) $options['git-sha'] : '';

if ( '' === $wordpressDir || '' === $baseUrl || '' === $outputPath || '' === $summaryPath ) {
    fwrite(STDERR, "Required: --wordpress-dir --base-url --output --summary\n");
    exit(2);
}
if ( $samples < 5 ) {
    fwrite(STDERR, "At least five measured pairs are required.\n");
    exit(2);
}
if ( $warmups < 1 ) {
    fwrite(STDERR, "At least one warm-up request is required before each measured state sample.\n");
    exit(2);
}
if ( ! is_dir($wordpressDir) ) {
    fwrite(STDERR, "WordPress directory does not exist: {$wordpressDir}\n");
    exit(2);
}
if ( ! function_exists('curl_init') ) {
    fwrite(STDERR, "PHP cURL extension is required.\n");
    exit(2);
}

$outputDir = dirname($outputPath);
$summaryDir = dirname($summaryPath);
foreach ( array_unique([$outputDir, $summaryDir]) as $dir ) {
    if ( ! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir) ) {
        throw new RuntimeException("Unable to create output directory {$dir}.");
    }
}

$probeDir = $wordpressDir . '/wp-content/wddtf-benchmark-output';
if ( ! is_dir($probeDir) && ! mkdir($probeDir, 0777, true) && ! is_dir($probeDir) ) {
    throw new RuntimeException('Unable to create benchmark probe output directory.');
}

$cookieJar = tempnam(sys_get_temp_dir(), 'wddtf-perf-cookie-');
if ( false === $cookieJar ) {
    throw new RuntimeException('Unable to create benchmark cookie jar.');
}

register_shutdown_function(static function() use ($cookieJar): void {
    if ( is_file($cookieJar) ) {
        @unlink($cookieJar);
    }
});

/** @return string */
function runWp(string $wordpressDir, array $arguments): string {
    $parts = ['wp', '--path=' . $wordpressDir];
    foreach ( $arguments as $argument ) {
        $parts[] = (string) $argument;
    }

    $command = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
    exec($command, $lines, $exitCode);
    $output = trim(implode("\n", $lines));

    if ( 0 !== $exitCode ) {
        throw new RuntimeException("WP-CLI failed ({$exitCode}): {$command}\n{$output}");
    }

    return $output;
}

function observeCanonicalBaseline(string $wordpressDir): WddtfPerformanceCanonicalBaseline {
    $saveQueries = '1' === runWp(
        $wordpressDir,
        ['eval', 'echo defined("SAVEQUERIES") && SAVEQUERIES ? "1" : "0";']
    );
    $externalHttpBlocked = '1' === runWp(
        $wordpressDir,
        ['eval', 'echo defined("WP_HTTP_BLOCK_EXTERNAL") && WP_HTTP_BLOCK_EXTERNAL ? "1" : "0";']
    );

    return WddtfPerformanceCanonicalBaseline::fromObservedRuntime($saveQueries, $externalHttpBlocked);
}

function setDeepState(string $wordpressDir, string $state): void {
    if ( 'active' === $state ) {
        runWp($wordpressDir, ['plugin', 'activate', 'wp-deep-diagnostics', '--quiet']);
        return;
    }

    runWp($wordpressDir, ['plugin', 'deactivate', 'wp-deep-diagnostics', '--quiet']);
}

function cleanProductReports(string $wordpressDir): void {
    $dir = $wordpressDir . '/wp-content/uploads/wp-deep-diagnostics';
    foreach ( glob($dir . '/report-*') ?: [] as $file ) {
        if ( is_file($file) && ! @unlink($file) ) {
            throw new RuntimeException("Unable to remove prior benchmark report {$file}.");
        }
    }
}

/** @return array{body:string,http_code:int,effective_url:string,response_wall_ms:float,started_ns:int,response_ended_ns:int} */
function httpRequest(string $url, ?string $cookieJar, ?array $postFields = null): array {
    $handle = curl_init($url);
    if ( false === $handle ) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'WDDTF-Performance-Qualification/1',
    ];

    if ( null !== $cookieJar && '' !== $cookieJar ) {
        $curlOptions[CURLOPT_COOKIEJAR]  = $cookieJar;
        $curlOptions[CURLOPT_COOKIEFILE] = $cookieJar;
    }

    curl_setopt_array($handle, $curlOptions);

    if ( null !== $postFields ) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($postFields, '', '&', PHP_QUERY_RFC3986));
    }

    $startedNs = hrtime(true);
    $body = curl_exec($handle);
    $responseEndedNs = hrtime(true);

    if ( false === $body ) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException("HTTP request failed for {$url}: {$error}");
    }

    $result = [
        'body'              => (string) $body,
        'http_code'         => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'effective_url'     => (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
        'response_wall_ms'  => round(($responseEndedNs - $startedNs) / 1_000_000, 3),
        'started_ns'        => $startedNs,
        'response_ended_ns' => $responseEndedNs,
    ];
    curl_close($handle);

    return $result;
}

function loginAdmin(string $baseUrl, string $cookieJar): void {
    $loginUrl = $baseUrl . '/wp-login.php';
    $landing = httpRequest($loginUrl, $cookieJar);
    if ( 200 !== $landing['http_code'] ) {
        throw new RuntimeException('Unable to load WordPress login page.');
    }

    $response = httpRequest($loginUrl, $cookieJar, [
        'log'         => 'admin',
        'pwd'         => 'ci-password',
        'wp-submit'   => 'Log In',
        'redirect_to' => $baseUrl . '/wp-admin/',
        'testcookie'  => '1',
    ]);

    if ( 200 !== $response['http_code'] || str_contains($response['effective_url'], 'wp-login.php') ) {
        throw new RuntimeException('Authenticated wp-admin session could not be established.');
    }
}

function waitForProbeMarker(string $metricPath, int $timeoutMs = 5000): int {
    $deadlineNs = hrtime(true) + ($timeoutMs * 1_000_000);

    do {
        clearstatcache(true, $metricPath);
        if ( is_file($metricPath) ) {
            return hrtime(true);
        }

        // This is completion polling, not synthetic workload/timing. The measured request
        // runs in the independent PHP server process; polling only observes when its
        // post-shutdown marker becomes visible.
        usleep(250);
    } while ( hrtime(true) < $deadlineNs );

    throw new RuntimeException('Post-shutdown benchmark marker did not appear within the validity timeout.');
}

/** @return array<string,mixed> */
function measuredRequest(
    string $baseUrl,
    string $path,
    ?string $cookieJar,
    string $probeDir,
    string $sampleId,
    bool $expectActive,
    bool $requiresAdmin
): array {
    $separator = str_contains($path, '?') ? '&' : '?';
    $url = $baseUrl . $path . $separator . 'wddtf_benchmark_sample=' . rawurlencode($sampleId);
    $metricPath = $probeDir . '/' . $sampleId . '.json';
    @unlink($metricPath);

    $response = httpRequest($url, $cookieJar);

    if ( 200 !== $response['http_code'] ) {
        throw new RuntimeException("Benchmark request {$sampleId} returned HTTP {$response['http_code']}.");
    }
    if ( $requiresAdmin && str_contains($response['effective_url'], 'wp-login.php') ) {
        throw new RuntimeException("Benchmark request {$sampleId} lost authenticated wp-admin context.");
    }

    // Some SAPIs/servers can make the response visible before PHP shutdown work completes.
    // Preserve both boundaries: client response time and external observation of the
    // post-finalize marker. The latter is the canonical full-lifecycle wall measurement.
    $lifecycleEndedNs = waitForProbeMarker($metricPath);
    $lifecycleWallMs = round(($lifecycleEndedNs - $response['started_ns']) / 1_000_000, 3);
    $postResponseMs = round(max(0, $lifecycleEndedNs - $response['response_ended_ns']) / 1_000_000, 3);

    $decoded = json_decode((string) file_get_contents($metricPath), true, 512, JSON_THROW_ON_ERROR);
    @unlink($metricPath);

    if ( ! is_array($decoded) || ($decoded['sample_id'] ?? null) !== $sampleId ) {
        throw new RuntimeException("Benchmark probe output for {$sampleId} is malformed.");
    }
    if ( ! array_key_exists('deep_active', $decoded) || (bool) $decoded['deep_active'] !== $expectActive ) {
        throw new RuntimeException("Benchmark request {$sampleId} did not run in the expected plugin state.");
    }
    if ( ! is_int($decoded['peak_memory_bytes'] ?? null) || ! is_int($decoded['db_query_count'] ?? null) ) {
        throw new RuntimeException("Benchmark request {$sampleId} is missing memory/query evidence.");
    }
    if ( ! is_float($decoded['late_shutdown_ms'] ?? null) && ! is_int($decoded['late_shutdown_ms'] ?? null) ) {
        throw new RuntimeException("Benchmark request {$sampleId} is missing late-shutdown evidence.");
    }

    return [
        'lifecycle_wall_ms' => $lifecycleWallMs,
        'response_wall_ms'  => $response['response_wall_ms'],
        'post_response_ms'  => $postResponseMs,
        'peak_memory_bytes' => $decoded['peak_memory_bytes'],
        'db_query_count'    => $decoded['db_query_count'],
        'late_shutdown_ms'  => (float) $decoded['late_shutdown_ms'],
    ];
}

function formatMs(float|int $value): string {
    return number_format((float) $value, 2, '.', '');
}

function formatMiB(float|int $bytes): string {
    return number_format(((float) $bytes) / 1048576, 2, '.', '');
}

try {
    $canonicalBaseline = observeCanonicalBaseline($wordpressDir);

    setDeepState($wordpressDir, 'control');
    loginAdmin($baseUrl, $cookieJar);

    $scenarios = [
        'frontend' => [
            'label' => 'Normal anonymous frontend',
            'path' => '/',
            'requires_admin' => false,
        ],
        'admin' => [
            'label' => 'Normal authenticated wp-admin',
            'path' => '/wp-admin/index.php',
            'requires_admin' => true,
        ],
        'diagnostic_workload' => [
            'label' => 'Bounded diagnostic workload',
            'path' => '/wp-admin/tools.php?page=wddtf-performance-fixture',
            'requires_admin' => true,
        ],
    ];

    $rawSamples = [];

    foreach ( $scenarios as $scenarioId => $scenario ) {
        for ( $pair = 1; $pair <= $samples; $pair++ ) {
            $order = 1 === ($pair % 2) ? ['control', 'active'] : ['active', 'control'];

            foreach ( $order as $state ) {
                setDeepState($wordpressDir, $state);
                cleanProductReports($wordpressDir);
                $requestCookieJar = $scenario['requires_admin'] ? $cookieJar : null;

                for ( $warmup = 1; $warmup <= $warmups; $warmup++ ) {
                    $warmupId = sprintf('warmup-%s-p%d-%s-%d', $scenarioId, $pair, $state, $warmup);
                    measuredRequest(
                        $baseUrl,
                        $scenario['path'],
                        $requestCookieJar,
                        $probeDir,
                        $warmupId,
                        'active' === $state,
                        $scenario['requires_admin']
                    );
                }

                // Keep report-retention history out of the sample itself. The directory is
                // already warm after the warm-up; only prior report-* files are removed.
                cleanProductReports($wordpressDir);

                $sampleId = sprintf('sample-%s-p%d-%s', $scenarioId, $pair, $state);
                $metrics = measuredRequest(
                    $baseUrl,
                    $scenario['path'],
                    $requestCookieJar,
                    $probeDir,
                    $sampleId,
                    'active' === $state,
                    $scenario['requires_admin']
                );

                $rawSamples[] = [
                    'scenario'          => $scenarioId,
                    'state'             => $state,
                    'pair'              => $pair,
                    'lifecycle_wall_ms' => $metrics['lifecycle_wall_ms'],
                    'response_wall_ms'  => $metrics['response_wall_ms'],
                    'post_response_ms'  => $metrics['post_response_ms'],
                    'peak_memory_bytes' => $metrics['peak_memory_bytes'],
                    'db_query_count'    => $metrics['db_query_count'],
                    'late_shutdown_ms'  => $metrics['late_shutdown_ms'],
                ];
            }
        }
    }

    $summaries = WddtfPerformanceBenchmarkSummary::summarize($rawSamples, $samples);

    $wordpressVersion = runWp($wordpressDir, ['core', 'version']);
    $pluginVersion = runWp($wordpressDir, ['plugin', 'get', 'wp-deep-diagnostics', '--field=version']);
    $databaseVersion = runWp($wordpressDir, ['eval', 'global $wpdb; echo $wpdb->db_version();']);

    $scenarioOutput = [];
    foreach ( $scenarios as $scenarioId => $scenario ) {
        $scenarioSamples = array_values(array_filter(
            $rawSamples,
            static fn(array $sample): bool => $sample['scenario'] === $scenarioId
        ));

        $scenarioOutput[] = [
            'id'      => $scenarioId,
            'label'   => $scenario['label'],
            'auth'    => $scenario['requires_admin'] ? 'authenticated-admin' : 'anonymous',
            'samples' => $scenarioSamples,
            'summary' => $summaries[$scenarioId],
        ];
    }

    $result = [
        'schema_version' => 1,
        'generated_at_utc' => gmdate('c'),
        'environment' => [
            'git_sha' => $gitSha,
            'deep_diagnostics_version' => $pluginVersion,
            'wordpress_version' => $wordpressVersion,
            'php_version' => PHP_VERSION,
            'database_version' => $databaseVersion,
            'server_sapi' => 'cli-server',
            'opcache_enable_cli' => (bool) ini_get('opcache.enable_cli'),
            ...$canonicalBaseline->environmentFields(),
        ],
        'measurement' => [
            'measured_pairs_per_scenario' => $samples,
            'warmups_before_each_state_sample' => $warmups,
            'pair_order' => 'odd pairs control-active; even pairs active-control',
            'lifecycle_wall_clock' => 'external monotonic time from request start until the post-finalize shutdown marker is observed; canonical total-overhead measure',
            'response_wall_clock' => 'external PHP cURL client monotonic time until the HTTP response completes; may exclude post-response shutdown work',
            'post_response' => 'external time between client response completion and observation of the post-finalize marker; marker polled every 250 microseconds with a 5 second validity timeout',
            'late_shutdown' => 'shutdown action priority 9998 through PHP_INT_MAX; active path includes Manager::finalize() at shutdown priority 9999 plus later WordPress shutdown hooks',
            'memory' => 'PHP request peak real memory at post-shutdown probe',
            'database_queries' => 'wpdb->num_queries at post-shutdown probe; does not require SAVEQUERIES',
            'report_history_policy' => 'report-* files removed outside measured requests after warm-up so samples measure a warm report directory without accumulating benchmark history',
        ],
        'scenarios' => $scenarioOutput,
        'caveats' => [
            'No numeric performance acceptance threshold is enforced in this characterization batch.',
            'Disposable WordPress/GitHub-hosted-runner evidence is not universal production overhead proof.',
            'SAVEQUERIES is not enabled in the canonical baseline; its generic WordPress cost is therefore not attributed to Deep Diagnostics.',
            'The post-response measurement includes marker-observation polling latency and is not presented with sub-millisecond statistical precision.',
            'The late-shutdown metric is a narrow phase boundary around Deep finalization, not a generic profiler trace.',
        ],
    ];

    $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($outputPath, $encoded, LOCK_EX);

    $markdown = [];
    $markdown[] = '# Deep Diagnostics performance/overhead characterization';
    $markdown[] = '';
    $markdown[] = sprintf(
        'Environment: WordPress %s, PHP %s, database %s, Deep Diagnostics %s. Samples: %d matched pairs/scenario; %d warm-up request(s) before every measured state sample.',
        $wordpressVersion,
        PHP_VERSION,
        $databaseVersion,
        $pluginVersion,
        $samples,
        $warmups
    );
    $markdown[] = '';
    $markdown[] = '| Scenario | Full lifecycle control median (range), ms | Full lifecycle active median (range), ms | Paired lifecycle delta median [p25–p75], ms | Paired response delta median, ms | Paired post-response delta median, ms | Peak-memory paired delta median, MiB | DB-query paired delta median | Late-shutdown paired delta median, ms |';
    $markdown[] = '| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |';

    foreach ( $scenarioOutput as $scenario ) {
        $summary = $scenario['summary'];
        $lifecycle = $summary['lifecycle_wall_ms'];
        $response = $summary['response_wall_ms'];
        $postResponse = $summary['post_response_ms'];
        $memory = $summary['peak_memory_bytes'];
        $queries = $summary['db_query_count'];
        $shutdown = $summary['late_shutdown_ms'];

        $markdown[] = sprintf(
            '| %s | %s (%s–%s) | %s (%s–%s) | %s [%s–%s] | %s | %s | %s | %s | %s |',
            $scenario['label'],
            formatMs($lifecycle['control']['median']),
            formatMs($lifecycle['control']['min']),
            formatMs($lifecycle['control']['max']),
            formatMs($lifecycle['active']['median']),
            formatMs($lifecycle['active']['min']),
            formatMs($lifecycle['active']['max']),
            formatMs($lifecycle['delta']['paired']['median']),
            formatMs($lifecycle['delta']['paired']['p25']),
            formatMs($lifecycle['delta']['paired']['p75']),
            formatMs($response['delta']['paired']['median']),
            formatMs($postResponse['delta']['paired']['median']),
            formatMiB($memory['delta']['paired']['median']),
            formatMs($queries['delta']['paired']['median']),
            formatMs($shutdown['delta']['paired']['median'])
        );
    }

    $markdown[] = '';
    $markdown[] = 'The canonical total-overhead figure is **full lifecycle wall time**, which waits for the post-finalize marker. Response wall time is retained separately because this runtime can return a response before shutdown/finalization is complete.';
    $markdown[] = '';
    $markdown[] = 'Positive deltas mean the active request used more time/memory/queries than its matched inactive control. Negative pair deltas can occur from runner noise; use the median and distribution rather than a single request.';
    $markdown[] = '';
    $markdown[] = '**Validity policy:** this job fails for missing pairs, malformed metrics, plugin-state mismatch, missing sample counts, or when the post-shutdown marker never appears within the bounded validity timeout. It intentionally does **not** fail on any arbitrary overhead number.';
    $markdown[] = '';
    $markdown[] = '**Claim ceiling:** these numbers characterize this disposable runtime only. They do not establish production traffic, arbitrary hosting, every plugin/theme combination, or SRWF production behavior.';
    $markdown[] = '';

    file_put_contents($summaryPath, implode("\n", $markdown), LOCK_EX);

    echo "Benchmark result: {$outputPath}\n";
    echo "Benchmark summary: {$summaryPath}\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Performance qualification failed: {$error->getMessage()}\n");
    exit(1);
}
