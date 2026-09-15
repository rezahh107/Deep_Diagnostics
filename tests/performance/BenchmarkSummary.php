<?php
declare(strict_types=1);

final class WddtfPerformanceBenchmarkSummary {
    /**
     * @param array<int,array<string,mixed>> $samples
     * @return array<string,array<string,mixed>>
     */
    public static function summarize(array $samples, int $expectedPairs): array {
        if ( $expectedPairs < 2 ) {
            throw new InvalidArgumentException('At least two measured pairs are required.');
        }

        $grouped = [];

        foreach ( $samples as $sample ) {
            self::validateSample($sample, $expectedPairs);
            $scenario = $sample['scenario'];
            $state    = $sample['state'];
            $pair     = $sample['pair'];

            if ( isset($grouped[$scenario][$state][$pair]) ) {
                throw new RuntimeException("Duplicate {$scenario}/{$state} sample for pair {$pair}.");
            }

            $grouped[$scenario][$state][$pair] = $sample;
        }

        if ( [] === $grouped ) {
            throw new RuntimeException('No benchmark samples were provided.');
        }

        $result = [];

        foreach ( $grouped as $scenario => $states ) {
            foreach ( ['control', 'active'] as $state ) {
                if ( ! isset($states[$state]) || count($states[$state]) !== $expectedPairs ) {
                    $actual = isset($states[$state]) ? count($states[$state]) : 0;
                    throw new RuntimeException("Scenario {$scenario} expected {$expectedPairs} {$state} samples, got {$actual}.");
                }
            }

            for ( $pair = 1; $pair <= $expectedPairs; $pair++ ) {
                if ( ! isset($states['control'][$pair], $states['active'][$pair]) ) {
                    throw new RuntimeException("Scenario {$scenario} pair {$pair} is not comparable.");
                }
            }

            $metrics = [
                'wall_ms'            => 'float',
                'peak_memory_bytes'  => 'int',
                'db_query_count'     => 'int',
                'late_shutdown_ms'   => 'float',
            ];

            $scenarioSummary = [];

            foreach ( $metrics as $metric => $kind ) {
                $control = [];
                $active  = [];
                $paired  = [];

                for ( $pair = 1; $pair <= $expectedPairs; $pair++ ) {
                    $controlValue = (float) $states['control'][$pair][$metric];
                    $activeValue  = (float) $states['active'][$pair][$metric];
                    $control[]    = $controlValue;
                    $active[]     = $activeValue;
                    $paired[]     = $activeValue - $controlValue;
                }

                $controlStats = self::stats($control, $kind);
                $activeStats  = self::stats($active, $kind);
                $deltaStats   = self::stats($paired, $kind);
                $medianDelta  = (float) $activeStats['median'] - (float) $controlStats['median'];

                $summary = [
                    'control' => $controlStats,
                    'active'  => $activeStats,
                    'delta'   => [
                        'active_median_minus_control_median' => self::roundForKind($medianDelta, $kind),
                        'paired'                              => $deltaStats,
                    ],
                ];

                if ( 'wall_ms' === $metric && (float) $controlStats['median'] > 0.0 ) {
                    $summary['delta']['median_relative_percent'] = round(
                        ($medianDelta / (float) $controlStats['median']) * 100,
                        1
                    );
                }

                $scenarioSummary[$metric] = $summary;
            }

            $result[(string) $scenario] = $scenarioSummary;
        }

        ksort($result);
        return $result;
    }

    /** @param array<string,mixed> $sample */
    private static function validateSample(array $sample, int $expectedPairs): void {
        foreach ( ['scenario', 'state', 'pair', 'wall_ms', 'peak_memory_bytes', 'db_query_count', 'late_shutdown_ms'] as $key ) {
            if ( ! array_key_exists($key, $sample) ) {
                throw new RuntimeException("Benchmark sample is missing {$key}.");
            }
        }

        if ( ! is_string($sample['scenario']) || 1 !== preg_match('/^[a-z0-9_-]+$/', $sample['scenario']) ) {
            throw new RuntimeException('Benchmark scenario id is malformed.');
        }

        if ( ! in_array($sample['state'], ['control', 'active'], true) ) {
            throw new RuntimeException('Benchmark sample state must be control or active.');
        }

        if ( ! is_int($sample['pair']) || $sample['pair'] < 1 || $sample['pair'] > $expectedPairs ) {
            throw new RuntimeException('Benchmark sample pair is outside the expected range.');
        }

        foreach ( ['wall_ms', 'peak_memory_bytes', 'db_query_count', 'late_shutdown_ms'] as $metric ) {
            if ( ! is_int($sample[$metric]) && ! is_float($sample[$metric]) ) {
                throw new RuntimeException("Benchmark metric {$metric} is not numeric.");
            }
            if ( ! is_finite((float) $sample[$metric]) || (float) $sample[$metric] < 0 ) {
                throw new RuntimeException("Benchmark metric {$metric} is invalid.");
            }
        }
    }

    /**
     * @param array<int,float> $values
     * @return array{median:float|int,p25:float|int,p75:float|int,min:float|int,max:float|int}
     */
    private static function stats(array $values, string $kind): array {
        sort($values, SORT_NUMERIC);

        return [
            'median' => self::roundForKind(self::quantile($values, 0.50), $kind),
            'p25'    => self::roundForKind(self::quantile($values, 0.25), $kind),
            'p75'    => self::roundForKind(self::quantile($values, 0.75), $kind),
            'min'    => self::roundForKind((float) $values[0], $kind),
            'max'    => self::roundForKind((float) $values[count($values) - 1], $kind),
        ];
    }

    /** @param array<int,float> $sorted */
    private static function quantile(array $sorted, float $q): float {
        $count = count($sorted);
        if ( 1 === $count ) {
            return (float) $sorted[0];
        }

        $position = ($count - 1) * $q;
        $lower    = (int) floor($position);
        $upper    = (int) ceil($position);

        if ( $lower === $upper ) {
            return (float) $sorted[$lower];
        }

        $weight = $position - $lower;
        return ((float) $sorted[$lower] * (1 - $weight)) + ((float) $sorted[$upper] * $weight);
    }

    private static function roundForKind(float $value, string $kind): float|int {
        if ( 'int' === $kind ) {
            return (int) round($value);
        }

        return round($value, 3);
    }
}
