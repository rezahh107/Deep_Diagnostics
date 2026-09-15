<?php
declare(strict_types=1);

namespace WDDTF\Diagnostics;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Deterministic, evidence-first synthesis for one normal WordPress request.
 *
 * This class deliberately does not infer generic latency from subsystem presence.
 * It promotes only measured request-local timing evidence, preserves uncertainty,
 * and keeps Cron/Gravity classifications as independent diagnostic context.
 */
final class CausalSynthesis {
    private const MATERIAL_MIN_MS = 100.0;
    private const MATERIAL_REQUEST_FRACTION = 0.30;
    private const SLOW_BOUNDARY_MIN_MS = 100.0;
    private const SLOW_BOUNDARY_REQUEST_FRACTION = 0.25;
    private const AUTOLOAD_RISK_BYTES = 1048576;
    private const ASSET_RISK_COUNT = 30;

    public function synthesize(array $snapshot): array {
        $elapsedMs = max(0.0, (float) ($snapshot['meta']['elapsed_ms'] ?? 0));
        $findings = [];
        $unknowns = [];

        $this->appendHttpFinding($snapshot, $elapsedMs, $findings);
        $this->appendDatabaseFinding($snapshot, $elapsedMs, $findings, $unknowns);
        $this->appendLifecycleFinding($snapshot, $elapsedMs, $findings);
        $this->appendRiskFindings($snapshot, $findings);

        $ranked = $this->rankFindings($findings);
        $strongSignalIds = array_values(array_map(
            static fn(array $finding): string => (string) $finding['id'],
            array_filter($ranked, static fn(array $finding): bool => 'strong_signal' === ($finding['strength'] ?? ''))
        ));

        if ( count($strongSignalIds) > 1 ) {
            $status = 'multiple_signals';
            $result = __('Multiple measured signals materially overlap this request. DEEP cannot choose one culprit from the retained evidence.', 'wp-deep-diagnostics');
        } elseif ( 1 === count($strongSignalIds) ) {
            $status = 'strong_signal';
            $result = (string) ($ranked[0]['result'] ?? __('A measured request-local signal materially overlaps the observed latency.', 'wp-deep-diagnostics'));
        } elseif ( $this->hasStrength($ranked, 'observed_boundary') ) {
            $status = 'observed_boundary';
            $result = __('DEEP found a slow lifecycle boundary, but the evidence does not identify what inside that interval consumed the time.', 'wp-deep-diagnostics');
        } elseif ( $this->hasStrength($ranked, 'risk_signal') ) {
            $status = 'risk_signals_only';
            $result = __('DEEP found risk signals, but no measured evidence ties them to the observed request latency.', 'wp-deep-diagnostics');
        } else {
            $status = 'insufficient_evidence';
            $result = __('No strong request-local causal signal is supported by the retained evidence.', 'wp-deep-diagnostics');
        }

        if ( empty($findings) ) {
            $findings[] = [
                'id'             => 'insufficient_evidence',
                'scope'          => 'request',
                'strength'       => 'unknown',
                'title'          => __('Insufficient evidence', 'wp-deep-diagnostics'),
                'result'         => __('No measured subsystem signal materially explains this request.', 'wp-deep-diagnostics'),
                'meaning'        => __('The retained evidence is not enough to name a likely latency source without guessing.', 'wp-deep-diagnostics'),
                'observed_facts' => [
                    sprintf(__('Observed end-to-end request time: %.1f ms.', 'wp-deep-diagnostics'), $elapsedMs),
                ],
                'claim_ceiling'  => __('Absence of a strong retained signal does not prove that the request is healthy or that unobserved work was fast.', 'wp-deep-diagnostics'),
                'next_step'      => __('Reproduce a representative slow request and collect the missing timing evidence for the subsystem you need to discriminate.', 'wp-deep-diagnostics'),
                'evidence'       => ['elapsed_ms' => round($elapsedMs, 2)],
            ];
            $ranked = $findings;
        }

        $strongestFindingIds = [];
        if ( ! empty($ranked) ) {
            $topRank = self::strengthRank((string) ($ranked[0]['strength'] ?? ''));
            if ( $topRank > 0 ) {
                foreach ( $ranked as $finding ) {
                    if ( self::strengthRank((string) ($finding['strength'] ?? '')) !== $topRank ) {
                        break;
                    }
                    $strongestFindingIds[] = (string) ($finding['id'] ?? '');
                }
            }
        }

        return [
            'version'               => 1,
            'status'                => $status,
            'result'                => $result,
            'strongest_finding_ids' => $strongestFindingIds,
            'findings'              => $ranked,
            'unknowns'              => array_values(array_unique($unknowns)),
            'subsystem_context'      => [
                'cron' => [
                    'qualification_status' => $snapshot['cron']['qualification']['status'] ?? 'not_started',
                    'qualification_reason' => $snapshot['cron']['qualification']['reason'] ?? null,
                ],
                'gravity' => [
                    'server_classification'  => $snapshot['gravity']['inbox_observation']['analysis']['classification'] ?? null,
                    'browser_classification' => $snapshot['gravity']['inbox_observation']['browser_analysis']['classification'] ?? null,
                ],
                'generic_latency_attribution' => false,
                'claim_ceiling' => __('Cron and Gravity evidence remains independently classified and is not attributed to generic request latency merely because it is present in the same report.', 'wp-deep-diagnostics'),
            ],
        ];
    }

    private function appendHttpFinding(array $snapshot, float $elapsedMs, array &$findings): void {
        $requests = is_array($snapshot['http_requests'] ?? null) ? $snapshot['http_requests'] : [];
        if ( empty($requests) ) {
            return;
        }

        $blocking = array_values(array_filter(
            $requests,
            static fn(array $request): bool => ! empty($request['blocking']) && is_numeric($request['duration'] ?? null)
        ));

        $blockingMs = array_sum(array_map(
            static fn(array $request): float => max(0.0, (float) $request['duration']) * 1000,
            $blocking
        ));
        $share = $elapsedMs > 0 ? $blockingMs / $elapsedMs : null;
        $material = ! empty($blocking)
            && $blockingMs >= self::MATERIAL_MIN_MS
            && null !== $share
            && $share >= self::MATERIAL_REQUEST_FRACTION;

        if ( $material ) {
            $findings[] = [
                'id'             => 'blocking_http_latency',
                'scope'          => 'http',
                'strength'       => 'strong_signal',
                'title'          => __('Measured blocking HTTP latency', 'wp-deep-diagnostics'),
                'result'         => __('Blocking outbound HTTP consumed a material part of this request.', 'wp-deep-diagnostics'),
                'meaning'        => __('DEEP measured synchronous outbound HTTP time inside the same request. That measured wait directly contributes to the request duration.', 'wp-deep-diagnostics'),
                'observed_facts' => [
                    sprintf(__('Blocking HTTP calls measured: %d.', 'wp-deep-diagnostics'), count($blocking)),
                    sprintf(__('Measured blocking HTTP time: %.1f ms of %.1f ms total request time.', 'wp-deep-diagnostics'), $blockingMs, $elapsedMs),
                ],
                'claim_ceiling'  => __('This proves measured blocking wait, not why the remote service or network took that time, and not that HTTP is the only latency source.', 'wp-deep-diagnostics'),
                'next_step'      => __('Inspect the slowest redacted HTTP samples and reproduce with the responsible integration isolated or instrumented at its supported boundary.', 'wp-deep-diagnostics'),
                'evidence'       => [
                    'blocking_request_count' => count($blocking),
                    'blocking_ms'            => round($blockingMs, 2),
                    'request_elapsed_ms'     => round($elapsedMs, 2),
                    'request_share'          => round($share, 4),
                ],
            ];
            return;
        }

        $findings[] = [
            'id'             => 'http_activity_observed',
            'scope'          => 'http',
            'strength'       => 'observation',
            'title'          => __('HTTP activity observed without material latency correlation', 'wp-deep-diagnostics'),
            'result'         => __('HTTP activity was observed, but the retained timing does not support a causal latency claim.', 'wp-deep-diagnostics'),
            'meaning'        => __('The presence of HTTP requests alone is not a diagnosis.', 'wp-deep-diagnostics'),
            'observed_facts' => [
                sprintf(__('HTTP calls observed: %d.', 'wp-deep-diagnostics'), count($requests)),
                sprintf(__('Measured blocking HTTP time retained: %.1f ms.', 'wp-deep-diagnostics'), $blockingMs),
            ],
            'claim_ceiling'  => __('DEEP cannot attribute the request latency to HTTP from activity alone or from timing that does not materially overlap the request.', 'wp-deep-diagnostics'),
            'next_step'      => __('Capture another representative slow request and compare measured blocking HTTP duration with the end-to-end request time.', 'wp-deep-diagnostics'),
            'evidence'       => [
                'request_count'          => count($requests),
                'blocking_request_count' => count($blocking),
                'blocking_ms'            => round($blockingMs, 2),
                'request_elapsed_ms'     => round($elapsedMs, 2),
                'request_share'          => null === $share ? null : round($share, 4),
            ],
        ];
    }

    private function appendDatabaseFinding(array $snapshot, float $elapsedMs, array &$findings, array &$unknowns): void {
        $database = is_array($snapshot['queries'] ?? null) ? $snapshot['queries'] : [];
        $timingAvailable = array_key_exists('queries', $database) && is_array($database['queries']);

        if ( ! $timingAvailable ) {
            $unknown = __('Database/query causality is unknown because precise query timing was not retained for this request.', 'wp-deep-diagnostics');
            $unknowns[] = $unknown;
            $findings[] = [
                'id'             => 'database_timing_unavailable',
                'scope'          => 'database',
                'strength'       => 'unknown',
                'title'          => __('Database timing unavailable', 'wp-deep-diagnostics'),
                'result'         => __('DEEP cannot diagnose query latency from this request.', 'wp-deep-diagnostics'),
                'meaning'        => $unknown,
                'observed_facts' => [
                    (string) ($database['warning'] ?? __('Precise query timing is unavailable.', 'wp-deep-diagnostics')),
                ],
                'claim_ceiling'  => __('Query count, SQL presence, or other subsystem signals are not substitutes for measured query duration.', 'wp-deep-diagnostics'),
                'next_step'      => __('Enable SAVEQUERIES only in an appropriate controlled diagnostic environment, reproduce the request, then compare measured query time with end-to-end latency.', 'wp-deep-diagnostics'),
                'evidence'       => ['timing_available' => false],
            ];
            return;
        }

        $queries = $database['queries'];
        $measured = array_values(array_filter(
            $queries,
            static fn(array $query): bool => is_numeric($query['time'] ?? null)
        ));
        $retainedMs = array_sum(array_map(
            static fn(array $query): float => max(0.0, (float) $query['time']) * 1000,
            $measured
        ));
        $slowestMs = 0.0;
        foreach ( $measured as $query ) {
            $slowestMs = max($slowestMs, max(0.0, (float) $query['time']) * 1000);
        }
        $share = $elapsedMs > 0 ? $retainedMs / $elapsedMs : null;
        $material = $retainedMs >= self::MATERIAL_MIN_MS
            && null !== $share
            && $share >= self::MATERIAL_REQUEST_FRACTION;

        if ( $material ) {
            $findings[] = [
                'id'             => 'measured_database_latency',
                'scope'          => 'database',
                'strength'       => 'strong_signal',
                'title'          => __('Measured database/query latency', 'wp-deep-diagnostics'),
                'result'         => __('Retained measured query time consumed a material part of this request.', 'wp-deep-diagnostics'),
                'meaning'        => __('DEEP has direct query-duration measurements for this request, so the retained database time is a supported latency contributor.', 'wp-deep-diagnostics'),
                'observed_facts' => [
                    sprintf(__('Measured queries retained: %d.', 'wp-deep-diagnostics'), count($measured)),
                    sprintf(__('Retained measured query time: %.1f ms of %.1f ms total request time.', 'wp-deep-diagnostics'), $retainedMs, $elapsedMs),
                    sprintf(__('Slowest retained query duration: %.1f ms.', 'wp-deep-diagnostics'), $slowestMs),
                ],
                'claim_ceiling'  => __('The collector retains only bounded slow-query evidence, so this does not prove the full database total or the underlying reason for a slow query.', 'wp-deep-diagnostics'),
                'next_step'      => __('Inspect the slowest redacted query samples and their call stacks, then reproduce against the owning plugin/theme path before changing database configuration.', 'wp-deep-diagnostics'),
                'evidence'       => [
                    'measured_query_count' => count($measured),
                    'retained_query_ms'    => round($retainedMs, 2),
                    'slowest_query_ms'     => round($slowestMs, 2),
                    'request_elapsed_ms'   => round($elapsedMs, 2),
                    'request_share'        => round($share, 4),
                    'bounded_sample'       => true,
                ],
            ];
        }
    }

    private function appendLifecycleFinding(array $snapshot, float $elapsedMs, array &$findings): void {
        if ( $elapsedMs <= 0 ) {
            return;
        }

        $timeline = is_array($snapshot['timeline'] ?? null) ? $snapshot['timeline'] : [];
        $thresholdMs = max(self::SLOW_BOUNDARY_MIN_MS, $elapsedMs * self::SLOW_BOUNDARY_REQUEST_FRACTION);

        foreach ( $timeline as $event ) {
            if ( ! is_numeric($event['data']['elapsed_since_prev'] ?? null) ) {
                continue;
            }

            $boundaryMs = max(0.0, (float) $event['data']['elapsed_since_prev'] * 1000);
            if ( $boundaryMs < $thresholdMs ) {
                continue;
            }

            $layer = is_string($event['layer'] ?? null) && '' !== $event['layer']
                ? $event['layer']
                : 'unknown';
            $share = $boundaryMs / $elapsedMs;

            $findings[] = [
                'id'             => 'slow_lifecycle_boundary',
                'scope'          => 'lifecycle',
                'strength'       => 'observed_boundary',
                'title'          => __('First observed slow lifecycle boundary', 'wp-deep-diagnostics'),
                'result'         => sprintf(__('A %.1f ms lifecycle interval was observed.', 'wp-deep-diagnostics'), $boundaryMs),
                'meaning'        => __('This is the first retained lifecycle interval large enough to explain a material part of the request, but DEEP cannot see which work inside the interval consumed the time.', 'wp-deep-diagnostics'),
                'observed_facts' => [
                    sprintf(__('Observed lifecycle interval: %.1f ms.', 'wp-deep-diagnostics'), $boundaryMs),
                ],
                'claim_ceiling'  => __('The lifecycle boundary localizes the delay between two observations; it does not attribute the delay to the hook at the end of the interval or to any specific callback inside it.', 'wp-deep-diagnostics'),
                'next_step'      => __('Instrument or profile the code executing between the surrounding lifecycle observations, starting with the owning callbacks in that interval.', 'wp-deep-diagnostics'),
                'evidence'       => [
                    'end_boundary'       => $layer,
                    'elapsed_ms'         => round($boundaryMs, 2),
                    'request_elapsed_ms' => round($elapsedMs, 2),
                    'request_share'      => round($share, 4),
                ],
            ];
            break;
        }
    }

    private function appendRiskFindings(array $snapshot, array &$findings): void {
        $autoloadBytes = max(0, (int) ($snapshot['system']['autoload_size'] ?? 0));
        if ( $autoloadBytes > self::AUTOLOAD_RISK_BYTES ) {
            $findings[] = [
                'id'             => 'autoload_size_risk',
                'scope'          => 'cache',
                'strength'       => 'risk_signal',
                'title'          => __('Large autoload footprint', 'wp-deep-diagnostics'),
                'result'         => __('The autoloaded option footprint is large enough to merit inspection.', 'wp-deep-diagnostics'),
                'meaning'        => __('Large autoload data can increase memory and option-loading work, but size alone does not prove it caused this request latency.', 'wp-deep-diagnostics'),
                'observed_facts' => [
                    sprintf(__('Autoloaded option bytes observed: %d.', 'wp-deep-diagnostics'), $autoloadBytes),
                ],
                'claim_ceiling'  => __('DEEP has no request-local timing correlation that attributes the observed latency to autoload size.', 'wp-deep-diagnostics'),
                'next_step'      => __('Review the heaviest autoloaded options for ownership and necessity, then compare representative request timing before and after a controlled remediation.', 'wp-deep-diagnostics'),
                'evidence'       => ['autoload_bytes' => $autoloadBytes],
            ];
        }

        $assetCount = max(0, (int) ($snapshot['assets']['total_enqueued'] ?? 0));
        if ( $assetCount > self::ASSET_RISK_COUNT ) {
            $findings[] = [
                'id'             => 'asset_count_risk',
                'scope'          => 'assets',
                'strength'       => 'risk_signal',
                'title'          => __('High admin asset count', 'wp-deep-diagnostics'),
                'result'         => __('Many admin assets were enqueued on this request.', 'wp-deep-diagnostics'),
                'meaning'        => __('A high asset count can increase browser/network work, but count alone does not prove PHP request latency or user-perceived slowness.', 'wp-deep-diagnostics'),
                'observed_facts' => [
                    sprintf(__('Admin assets enqueued: %d.', 'wp-deep-diagnostics'), $assetCount),
                ],
                'claim_ceiling'  => __('DEEP did not measure browser transfer/render cost for these assets in this normal-request report.', 'wp-deep-diagnostics'),
                'next_step'      => __('Use browser developer tooling or an existing frontend performance tool to measure transfer/render cost before removing assets.', 'wp-deep-diagnostics'),
                'evidence'       => ['asset_count' => $assetCount],
            ];
        }
    }

    private function rankFindings(array $findings): array {
        usort(
            $findings,
            static fn(array $left, array $right): int => self::strengthRank((string) ($right['strength'] ?? '')) <=> self::strengthRank((string) ($left['strength'] ?? ''))
        );

        return $findings;
    }

    private function hasStrength(array $findings, string $strength): bool {
        foreach ( $findings as $finding ) {
            if ( $strength === ($finding['strength'] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    private static function strengthRank(string $strength): int {
        return match ($strength) {
            'strong_signal'     => 4,
            'observed_boundary' => 3,
            'risk_signal'       => 2,
            'observation'       => 1,
            default             => 0,
        };
    }
}
