<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Admin\Admin_Page;
use WDDTF\Diagnostics\Manager;

if ( ! class_exists('WddtfAdminDie') ) {
    final class WddtfAdminDie extends RuntimeException {}
}
if ( ! function_exists('esc_html__') ) {
    function esc_html__(string $text, string $domain = 'default'): string { return $text; }
}
if ( ! function_exists('esc_html') ) {
    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if ( ! function_exists('wp_die') ) {
    function wp_die(string $message = ''): never { throw new WddtfAdminDie($message); }
}
if ( ! function_exists('check_admin_referer') ) {
    function check_admin_referer(string|int $action = -1, string $query_arg = '_wpnonce'): int|false {
        $provided = isset($_POST[$query_arg]) && is_string($_POST[$query_arg]) ? wp_unslash($_POST[$query_arg]) : '';
        if ( hash_equals(wp_create_nonce($action), $provided) ) return 1;
        throw new WddtfAdminDie('invalid_nonce');
    }
}

final class AdminProviderActionTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_actions'] = [];
        $GLOBALS['wddtf_test_filters'] = [];
        $GLOBALS['wddtf_test_capabilities'] = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void {
        $_POST = [];
        $_FILES = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    public function test_provider_admin_actions_are_registered(): void {
        ( new Admin_Page(new Manager()) )->register();
        self::assertArrayHasKey('admin_post_wddtf_import_diagnostic_provider_bundle', $GLOBALS['wddtf_test_actions']);
        self::assertArrayHasKey('admin_post_wddtf_refresh_diagnostic_provider', $GLOBALS['wddtf_test_actions']);
        self::assertArrayHasKey('admin_post_wddtf_export_diagnostic_provider_evidence', $GLOBALS['wddtf_test_actions']);
    }

    public function test_unauthorized_user_cannot_import_provider_bundle(): void {
        $_POST['_wpnonce'] = wp_create_nonce('wddtf_import_diagnostic_provider_bundle');
        $this->expectException(WddtfAdminDie::class);
        $this->expectExceptionMessage('Access denied');
        ( new Admin_Page(new Manager()) )->importProviderBundle();
    }

    public function test_import_requires_valid_nonce_after_capability_check(): void {
        $GLOBALS['wddtf_test_capabilities']['manage_options'] = true;
        $_POST['_wpnonce'] = 'invalid-nonce';
        $this->expectException(WddtfAdminDie::class);
        $this->expectExceptionMessage('invalid_nonce');
        ( new Admin_Page(new Manager()) )->importProviderBundle();
    }
}
