<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if ( ! defined('WDDTF_VERSION') ) {
    define('WDDTF_VERSION', 'test');
}
if ( ! defined('HOUR_IN_SECONDS') ) {
    define('HOUR_IN_SECONDS', 3600);
}

$GLOBALS['wddtf_test_actions'] = [];
$GLOBALS['wddtf_test_filters'] = [];
$GLOBALS['wddtf_test_upload_dir'] = sys_get_temp_dir() . '/wddtf-tests-' . getmypid();

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
    $GLOBALS['wddtf_test_actions'][$hook][] = [$callback, $priority, $accepted_args];
    return true;
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
    $GLOBALS['wddtf_test_filters'][$hook][] = [$callback, $priority, $accepted_args];
    return true;
}

function __(string $text, string $domain = 'default'): string {
    return $text;
}

function sanitize_text_field(string $value): string {
    return trim(strip_tags($value));
}

function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false {
    return json_encode($value, $flags, $depth);
}

function is_wp_error(mixed $value): bool {
    return false;
}

function wp_upload_dir(): array {
    return [
        'basedir' => $GLOBALS['wddtf_test_upload_dir'],
        'error'   => false,
    ];
}

function trailingslashit(string $value): string {
    return rtrim($value, '/\\') . '/';
}

function wp_mkdir_p(string $path): bool {
    return is_dir($path) || mkdir($path, 0777, true);
}

function wp_generate_password(int $length = 12, bool $special_chars = true, bool $extra_special_chars = false): string {
    return substr('deterministicpassword', 0, $length);
}

require_once dirname(__DIR__) . '/src/Autoloader.php';
\WDDTF\Autoloader::register('WDDTF', dirname(__DIR__) . '/src');
