<?php
/**
 * Plugin Name: WP Deep Diagnostics
 * Version: 1.5.9
 * Requires PHP: 8.1
 * Description: Forensic admin latency telemetry. LLM-ready.
 * Text Domain: wp-deep-diagnostics
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( version_compare(PHP_VERSION, '8.1', '<') ) {
    add_action(
        'admin_notices',
        static function(): void {
            echo '<div class="notice notice-error"><p>' .
                esc_html__('WP Deep Diagnostics requires PHP 8.1+.', 'wp-deep-diagnostics') .
                '</p></div>';
        }
    );
    return;
}

define('WDDTF_VERSION', '1.5.9');
define('WDDTF_FILE', __FILE__);
define('WDDTF_PATH', plugin_dir_path(__FILE__));
define('WDDTF_URL', plugin_dir_url(__FILE__));
define('WDDTF_BASENAME', plugin_basename(__FILE__));

$autoload = WDDTF_PATH . 'vendor/autoload.php';

if ( file_exists($autoload) ) {
    require_once $autoload;
} else {
    require_once WDDTF_PATH . 'src/Autoloader.php';
    \WDDTF\Autoloader::register('WDDTF', WDDTF_PATH . 'src');
}

add_action(
    'plugins_loaded',
    static function(): void {
        load_plugin_textdomain('wp-deep-diagnostics', false, dirname(WDDTF_BASENAME) . '/languages');
        ( new \WDDTF\Plugin() )->boot();
    }
);
