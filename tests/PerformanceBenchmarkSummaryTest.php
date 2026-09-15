<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/performance/BenchmarkSummary.php';

final class PerformanceBenchmarkSummaryTest extends TestCase {
    public function testSummarizesMatchedPairsWithRobustDistributionStats(): void {
        $samples = [];
        foreach ( [
            1 => [10.0, 12.0, 100, 120, 20, 22, 1.0, 2.0],
            2 => [11.0, 15.0, 100, 130, 20, 24, 1.2, 2.4],
            3 => [50.0, 16.0, 100, 125, 20, 23, 1.1, 2.2],
        ] as $pair => $values ) {
            [$controlWall, $activeWall, $controlMemory, $activeMemory, $controlQueries, $activeQueries, $controlShutdown, $activeShutdown] = $values;
            foreach ( ['control', 'active'] as $state ) {
                $active = 'active' === $state;
                $samples[] = [
                    'scenario'          => 'frontend',
                    'state'             => $state,
                    'pair'              => $pair,
                    'wall_ms'           => $active ? $activeWall : $controlWall,
                    'peak_memory_bytes' => $active ? $activeMemory : $controlMemory,
                    'db_query_count'    => $active ? $activeQueries : $controlQueries,
                    'late_shutdown_ms'  => $active ? $activeShutdown : $controlShutdown,
                ];
            }
        }

        $summary = WddtfPerformanceBenchmarkSummary::summarize($samples, 3)['frontend'];

        self::assertSame(11.0, $summary['wall_ms']['control']['median']);
        self::assertSame(15.0, $summary['wall_ms']['active']['median']);
        self::assertSame(4.0, $summary['wall_ms']['delta']['active_median_minus_control_median']);
        self::assertSame(2.0, $summary['wall_ms']['delta']['paired']['median']);
        self::assertSame(25, $summary['peak_memory_bytes']['delta']['paired']['median']);
        self::assertSame(3, $summary['db_query_count']['delta']['paired']['median']);
        self::assertSame(1.1, $summary['late_shutdown_ms']['delta']['paired']['median']);
    }

    public function testRejectsMissingControlOrActivePair(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected 2 active samples');

        WddtfPerformanceBenchmarkSummary::summarize([
            $this->sample('control', 1),
            $this->sample('active', 1),
            $this->sample('control', 2),
        ], 2);
    }

    /** @return array<string,mixed> */
    private function sample(string $state, int $pair): array {
        return [
            'scenario'          => 'frontend',
            'state'             => $state,
            'pair'              => $pair,
            'wall_ms'           => 10.0,
            'peak_memory_bytes' => 100,
            'db_query_count'    => 10,
            'late_shutdown_ms'  => 1.0,
        ];
    }
}
