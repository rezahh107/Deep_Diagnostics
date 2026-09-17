<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProviderAdminMarkupTest extends TestCase {
    public function test_gpp_provider_surface_is_self_guided_before_technical_json(): void {
        $template = (string) file_get_contents(dirname(__DIR__) . '/templates/provider-diagnostics.php');

        foreach ( [
            'GPP Diagnostics',
            'What is this?',
            'What does it read?',
            'What does it intentionally not read?',
            'What does it need?',
            'Connection / evidence source',
            'What should I do?',
            'Current result',
            'What this means',
            'What this proves',
            'What remains unresolved / unproven',
            'What should I do next?',
            'Limitations',
            'Technical details / normalized JSON evidence',
        ] as $required ) {
            self::assertStringContainsString($required, $template);
        }

        self::assertLessThan(
            strpos($template, 'Technical details / normalized JSON evidence'),
            strpos($template, 'Current result')
        );
        self::assertLessThan(
            strpos($template, 'Technical details / normalized JSON evidence'),
            strpos($template, 'What should I do next?')
        );
        self::assertStringContainsString('Import GPP Support Bundle', $template);
        self::assertStringContainsString('Open an incident to see its ordered chain and first inconsistent boundary.', $template);
        self::assertStringContainsString('DEEP never treats timestamp proximity alone as proof', $template);
        self::assertStringContainsString('Trial / pre-release build:', $template);
    }

    public function test_provider_technical_output_is_progressively_disclosed_and_escaped(): void {
        $template = (string) file_get_contents(dirname(__DIR__) . '/templates/provider-diagnostics.php');

        self::assertStringContainsString('<details class="wddtf-trace">', $template);
        self::assertStringContainsString('esc_textarea', $template);
        self::assertStringContainsString('esc_html', $template);
        self::assertStringContainsString('wp_nonce_field', $template);
        self::assertStringContainsString('method="post"', $template);
    }
}
