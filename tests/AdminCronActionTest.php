<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Admin\Admin_Page;
use WDDTF\Cron\CronDiagnostics;
use WDDTF\Diagnostics\Manager;
use WDDTF\Diagnostics\SessionStore;

final class AdminCronActionTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_actions'] = [];
        $GLOBALS['wddtf_test_schedule_calls'] = [];
    }

    public function test_admin_registration_exposes_explicit_post_action_without_scheduling_probe(): void {
        $now = 1000;
        $clock = static function() use (&$now): int { return $now; };
        $cron = new CronDiagnostics(
            new SessionStore($clock, static fn(): string => 'ds-9999999999999999'),
            $clock
        );
        $page = new Admin_Page(new Manager($cron));

        $page->register();

        self::assertArrayHasKey('admin_post_wddtf_run_cron_qualification', $GLOBALS['wddtf_test_actions']);
        self::assertArrayHasKey('admin_menu', $GLOBALS['wddtf_test_actions']);
        self::assertArrayHasKey('admin_enqueue_scripts', $GLOBALS['wddtf_test_actions']);
        self::assertSame([], $GLOBALS['wddtf_test_schedule_calls']);
    }
}
