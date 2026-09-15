<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Admin\Admin_Page;
use WDDTF\Cron\CronDiagnostics;
use WDDTF\Diagnostics\Manager;
use WDDTF\Diagnostics\SessionStore;
use WDDTF\Gravity\GravityDiagnostics;

final class AdminGravityActionTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_actions'] = [];
        $GLOBALS['wddtf_test_filters'] = [];
        $GLOBALS['wddtf_test_transients'] = [];
    }

    public function test_admin_registration_exposes_explicit_inbox_observation_action_without_starting_session(): void {
        $now = 1000;
        $clock = static function() use (&$now): int { return $now; };
        $cron = new CronDiagnostics(
            new SessionStore($clock, static fn(): string => 'ds-cccccccccccccccc'),
            $clock
        );
        $gravity = new GravityDiagnostics(
            new SessionStore($clock, static fn(): string => 'ds-dddddddddddddddd'),
            $clock,
            microtime(true)
        );
        $page = new Admin_Page(new Manager($cron, $gravity));

        $page->register();

        self::assertArrayHasKey('admin_post_wddtf_start_gravityflow_inbox_observation', $GLOBALS['wddtf_test_actions']);
        self::assertArrayHasKey('admin_post_wddtf_run_cron_qualification', $GLOBALS['wddtf_test_actions']);
        self::assertArrayHasKey('admin_menu', $GLOBALS['wddtf_test_actions']);
        self::assertArrayHasKey('admin_enqueue_scripts', $GLOBALS['wddtf_test_actions']);
        self::assertSame([], $GLOBALS['wddtf_test_transients']);
    }
}
