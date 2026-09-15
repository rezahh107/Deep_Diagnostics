<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Collectors\AssetAnalyzer;

if ( ! class_exists('WP_Dependencies') ) {
    class WP_Dependencies {
        public array $queue = [];
        public array $registered = [];
    }
}

if ( ! defined('WP_CONTENT_DIR') ) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wddtf-content');
}

if ( ! function_exists('wp_parse_url') ) {
    function wp_parse_url(string $url, int $component = -1): mixed {
        return parse_url($url, $component);
    }
}

if ( ! function_exists('site_url') ) {
    function site_url(string $path = ''): string {
        return 'http://example.test' . ('' !== $path ? '/' . ltrim($path, '/') : '');
    }
}

if ( ! function_exists('content_url') ) {
    function content_url(string $path = ''): string {
        return 'http://example.test/wp-content' . ('' !== $path ? '/' . ltrim($path, '/') : '');
    }
}

final class AssetAnalyzerTest extends TestCase {
    protected function tearDown(): void {
        $GLOBALS['wddtf_test_is_admin'] = false;
        unset($GLOBALS['wp_scripts'], $GLOBALS['wp_styles']);
    }

    public function test_non_string_dependency_source_is_counted_but_not_inspected_as_url(): void {
        $GLOBALS['wddtf_test_is_admin'] = true;

        $scripts = new WP_Dependencies();
        $scripts->queue = ['dependency-alias', 'normal-script'];
        $scripts->registered = [
            'dependency-alias' => (object) ['src' => true],
            'normal-script'    => (object) ['src' => 'http://example.test/wp-content/plugins/example/script.js'],
        ];

        $styles = new WP_Dependencies();
        $GLOBALS['wp_scripts'] = $scripts;
        $GLOBALS['wp_styles'] = $styles;

        $snapshot = (new AssetAnalyzer())->snapshot();

        self::assertSame(2, $snapshot['total_enqueued']);
        self::assertCount(1, $snapshot['heavy']);
        self::assertSame('normal-script', $snapshot['heavy'][0]['handle']);
        self::assertSame('http://example.test/wp-content/plugins/example/script.js', $snapshot['heavy'][0]['src']);
    }
}
