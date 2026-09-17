<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Admin\ProviderLlmPanel;
use WDDTF\Diagnostics\Manager;

final class ProviderLlmPanelTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_actions'] = [];
        $GLOBALS['wddtf_test_capabilities'] = [];
    }

    public function test_registers_explicit_export_action_and_page_scoped_ui_hooks(): void {
        ( new ProviderLlmPanel(new Manager()) )->register();

        self::assertArrayHasKey('admin_post_wddtf_export_diagnostic_provider_llm', $GLOBALS['wddtf_test_actions']);
        self::assertArrayHasKey('admin_footer-tools_page_wp-deep-diagnostics', $GLOBALS['wddtf_test_actions']);
        self::assertArrayHasKey('admin_enqueue_scripts', $GLOBALS['wddtf_test_actions']);
    }

    public function test_panel_markup_exposes_copy_markdown_json_and_truthful_filename_boundary(): void {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Admin/ProviderLlmPanel.php');
        $script = (string) file_get_contents(dirname(__DIR__) . '/assets/admin.js');

        foreach ( [
            'Language-model handoff',
            'Report for language model',
            'Copy report',
            'Download Markdown',
            'Download JSON',
            'original uploaded filename is intentionally not retained',
            'wddtf_export_diagnostic_provider_llm',
            'wp_nonce_field',
            "current_user_can(self::CAPABILITY)",
        ] as $required ) {
            self::assertStringContainsString($required, $source);
        }

        self::assertStringContainsString('wddtf-gpp-diagnostics', $script);
        self::assertStringContainsString('data-wddtf-copy-target', $script);
        self::assertStringContainsString('data-wddtf-toggle-target', $script);
        self::assertStringContainsString('navigator.clipboard', $script);
    }
}
