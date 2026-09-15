<?php
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
            $recommendations[] = __('HTTP activity was observed. Use measured durations and surrounding timeline evidence before attributing latency to it.', 'wp-deep-diagnostics');
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
            $recommendations[] = __('No strong bottleneck signal detected. Enable SAVEQUERIES when database timing is needed and collect another representative request.', 'wp-deep-diagnostics');
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

        $bottlenecks = [
            [
                'name'  => __('HTTP Activity', 'wp-deep-diagnostics'),
                'score' => $httpCount ? 60 : 10,
            ],
            [
                'name'  => __('Autoload Options', 'wp-deep-diagnostics'),
                'score' => $autoloadScore,
            ],
            [
                'name'  => __('Admin Assets', 'wp-deep-diagnostics'),
                'score' => $assetScore,
            ],
        ];

        usort(
            $bottlenecks,
            static fn(array $left, array $right): int => $right['score'] <=> $left['score']
        );

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
                'cron'     => $snapshot['cron'] ?? [],
            ],
            'bottlenecks'     => $bottlenecks,
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
