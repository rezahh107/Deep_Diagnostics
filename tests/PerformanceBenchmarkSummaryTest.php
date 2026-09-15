<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/performance/BenchmarkSummary.php';

final class PerformanceBenchmarkSummaryTest extends TestCase {
    public function testSummarizesMatchedPairsWithRobustDistributionStats(): void {
        $samples = [];
        foreach ( [
            1 => [10.5, 10.0, 0.5, 12.5, 12.0, 0.5, 100, 120, 20, 22, 1.0, 2.0],
            2 => [11.5, 11.0, 0.5, 15.5, 15.0, 0.5, 100, 130, 20, 24, 1.2, 2.4],
            3 => [50.5, 50.0, 0.5, 16.5, 16.0, 0.5, 100, 125, 20, 23, 1.1, 2.2],
        ] as $pair => $values ) {
            [
                $controlLifecycle,
                $controlResponse,
                $controlPost,
                $activeLifecycle,
                $activeResponse,
                $activePost,
                $controlMemory,
                $activeMemory,
                $controlQueries,
                $activeQueries,
                $controlShutdown,
                $activeShutdown,
            ] = $values;

            foreach ( ['control', 'active'] as $state ) {
                $active = 'active' === $state;
                $samples[] = [
                    'scenario'          => 'frontend',
                    'state'             => $state,
                    'pair'              => $pair,
                    'lifecycle_wall_ms' => $active ? $activeLifecycle : $controlLifecycle,
                    'response_wall_ms'  => $active ? $activeResponse : $controlResponse,
                    'post_response_ms'  => $active ? $activePost : $controlPost,
                    'peak_memory_bytes' => $active ? $activeMemory : $controlMemory,
                    'db_query_count'    => $active ? $activeQueries : $controlQueries,
                    'late_shutdown_ms'  => $active ? $activeShutdown : $controlShutdown,
                ];
            }
        }

        $summary = WddtfPerformanceBenchmarkSummary::summarize($samples, 3)['frontend'];

        self::assertSame(11.5, $summary['lifecycle_wall_ms']['control']['median']);
        self::assertSame(15.5, $summary['lifecycle_wall_ms']['active']['median']);
        self::assertSame(4.0, $summary['lifecycle_wall_ms']['delta']['active_median_minus_control_median']);
        self::assertSame(2.0, $summary['lifecycle_wall_ms']['delta']['paired']['median']);
        self::assertSame(2.0, $summary['response_wall_ms']['delta']['paired']['median']);
        self::assertSame(0.0, $summary['post_response_ms']['delta']['paired']['median']);
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

    public function testRejectsLifecycleShorterThanClientResponse(): void {
        $invalid = $this->sample('control', 1);
        $invalid['lifecycle_wall_ms'] = 9.0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Lifecycle wall time cannot be shorter');

        WddtfPerformanceBenchmarkSummary::summarize([$invalid], 2);
    }

    /** @return array<string,mixed> */
    private function sample(string $state, int $pair): array {
        return [
            'scenario'          => 'frontend',
            'state'             => $state,
            'pair'              => $pair,
            'lifecycle_wall_ms' => 10.5,
            'response_wall_ms'  => 10.0,
            'post_response_ms'  => 0.5,
            'peak_memory_bytes' => 100,
            'db_query_count'    => 10,
            'late_shutdown_ms'  => 1.0,
        ];
    }
}
