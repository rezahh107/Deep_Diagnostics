<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/performance/CanonicalBaseline.php';

final class PerformanceBenchmarkBaselineTest extends TestCase {
    public function testCanonicalBaselinePassesAndEmitsTheSameObservedValues(): void {
        $baseline = WddtfPerformanceCanonicalBaseline::fromObservedRuntime(false, true);

        self::assertSame([
            'savequeries'            => false,
            'external_http_blocked' => true,
        ], $baseline->environmentFields());
    }

    public function testSaveQueriesDeviationFailsDeterministically(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAVEQUERIES must be disabled');

        WddtfPerformanceCanonicalBaseline::fromObservedRuntime(true, true);
    }

    public function testExternalHttpIsolationDeviationFailsDeterministically(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WP_HTTP_BLOCK_EXTERNAL must be true');

        WddtfPerformanceCanonicalBaseline::fromObservedRuntime(false, false);
    }

    public function testCombinedDeviationFailsWithoutHidingEitherViolation(): void {
        try {
            WddtfPerformanceCanonicalBaseline::fromObservedRuntime(true, false);
            self::fail('Combined noncanonical baseline was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('SAVEQUERIES must be disabled', $error->getMessage());
            self::assertStringContainsString('WP_HTTP_BLOCK_EXTERNAL must be true', $error->getMessage());
        }
    }
}
