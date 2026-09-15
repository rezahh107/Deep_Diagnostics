<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Collectors\EventCollector;

final class EventCollectorTest extends TestCase {
    public function test_elapsed_time_is_attributed_to_the_event_that_just_started(): void {
        $collector = new EventCollector();
        $collector->record('plugins_loaded');
        usleep(1000);
        $collector->record('after_setup_theme');

        $events = $collector->snapshot();

        self::assertArrayNotHasKey('elapsed_since_prev', $events[0]['data']);
        self::assertArrayHasKey('elapsed_since_prev', $events[1]['data']);
        self::assertGreaterThan(0, $events[1]['data']['elapsed_since_prev']);
    }
}
