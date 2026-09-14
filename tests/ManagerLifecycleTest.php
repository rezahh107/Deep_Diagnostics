<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\Manager;

final class ManagerLifecycleTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_actions'] = [];
        $GLOBALS['wddtf_test_filters'] = [];
    }

    public function test_no_argument_wordpress_hook_records_its_hook_name(): void {
        $manager = new Manager();
        $manager->boot();

        self::assertArrayHasKey('after_setup_theme', $GLOBALS['wddtf_test_actions']);
        $callback = $GLOBALS['wddtf_test_actions']['after_setup_theme'][0][0];

        $callback();

        $property = new ReflectionProperty($manager, 'events');
        $collector = $property->getValue($manager);
        $events = $collector->snapshot();

        self::assertSame('after_setup_theme', $events[0]['layer']);
    }
}
