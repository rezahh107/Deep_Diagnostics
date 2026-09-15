<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminNormalReportMarkupTest extends TestCase {
    public function test_normal_report_is_self_guided_before_progressive_technical_evidence(): void {
        $template = (string) file_get_contents(dirname(__DIR__) . '/templates/admin-page.php');

        self::assertStringContainsString('Last normal-request report', $template);
        self::assertStringContainsString('Plain-language meaning', $template);
        self::assertStringContainsString('Evidence that supports it', $template);
        self::assertStringContainsString('What DEEP cannot prove', $template);
        self::assertStringContainsString('Recommended next step', $template);
        self::assertStringContainsString('<details class="wddtf-trace">', $template);
        self::assertStringContainsString('Technical evidence and LLM-ready JSON', $template);
        self::assertStringContainsString('id="wddtf-llm-bundle"', $template);
        self::assertStringContainsString('dir="ltr"', $template);
        self::assertStringContainsString('screen-reader-text', $template);
    }

    public function test_post_pr9_browser_claim_ceiling_is_truthful_and_stale_claim_is_gone(): void {
        $template = (string) file_get_contents(dirname(__DIR__) . '/templates/admin-page.php');

        self::assertStringContainsString('Gravity Forms 3.1.1.1', $template);
        self::assertStringContainsString('Gravity Flow 3.1.0', $template);
        self::assertStringContainsString('WordPress 6.5', $template);
        self::assertStringContainsString('PHP 8.1.34', $template);
        self::assertStringContainsString('the expected Entry became visible through native Live Data Refresh', $template);
        self::assertStringContainsString("DEEP\\'s own browser classification still does not prove which Entry became visible", $template);
        self::assertStringNotContainsString('Authentic Gravity Flow browser behavior still requires qualification on a licensed site.', $template);
        self::assertStringContainsString('Entry visible to user proven', $template);
    }

    public function test_existing_scoped_admin_css_keeps_rtl_safe_layout_and_ltr_technical_isolation(): void {
        $css = (string) file_get_contents(dirname(__DIR__) . '/assets/admin.css');

        self::assertStringContainsString('.wddtf-wrap', $css);
        self::assertStringContainsString('direction: ltr', $css);
        self::assertStringContainsString('unicode-bidi: isolate', $css);
        self::assertStringContainsString('@media screen and (max-width: 782px)', $css);
        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr)', $css);
    }
}
