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
if ( ! defined('DAY_IN_SECONDS') ) {
    define('DAY_IN_SECONDS', 86400);
}

if ( ! class_exists('WP_Error') ) {
    final class WP_Error {
        public function __construct(
            private string $code,
            private string $message = '',
        ) {
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }
    }
}

if ( ! class_exists('wpdb') ) {
    class wpdb {
        public string $options = 'wp_options';
        public int $num_queries = 0;

        public function delete(string $table, array $where, array|string|null $whereFormat = null): int|false {
            if ( $table !== $this->options ) {
                return false;
            }

            $name = $where['option_name'] ?? null;
            $expectedValue = $where['option_value'] ?? null;
            if ( ! is_string($name) || ! array_key_exists($name, $GLOBALS['wddtf_test_options']) ) {
                return 0;
            }

            $actualValue = maybe_serialize($GLOBALS['wddtf_test_options'][$name]['value']);
            if ( ! is_string($expectedValue) || ! hash_equals($expectedValue, $actualValue) ) {
                return 0;
            }

            unset($GLOBALS['wddtf_test_options'][$name]);
            return 1;
        }
    }
}

$GLOBALS['wddtf_test_actions'] = [];
$GLOBALS['wddtf_test_filters'] = [];
$GLOBALS['wddtf_test_upload_dir'] = sys_get_temp_dir() . '/wddtf-tests-' . getmypid();
$GLOBALS['wddtf_test_transients'] = [];
$GLOBALS['wddtf_test_transient_failures'] = [];
$GLOBALS['wddtf_test_options'] = [];
$GLOBALS['wddtf_test_cron_events'] = [];
$GLOBALS['wddtf_test_ready_cron_jobs'] = [];
$GLOBALS['wddtf_test_schedule_calls'] = [];
$GLOBALS['wddtf_test_schedule_result'] = true;
$GLOBALS['wddtf_test_hide_scheduled_events'] = false;
$GLOBALS['wddtf_test_is_ajax'] = false;
$GLOBALS['wddtf_test_is_admin'] = false;
$GLOBALS['wddtf_test_did_actions'] = [];
$GLOBALS['wddtf_test_auth_salt'] = 'test-only-server-held-auth-salt-0123456789abcdef';

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
    $GLOBALS['wddtf_test_actions'][$hook][] = [$callback, $priority, $accepted_args];
    return true;
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
    $GLOBALS['wddtf_test_filters'][$hook][] = [$callback, $priority, $accepted_args];
    return true;
}

function did_action(string $hook): int {
    return (int) ($GLOBALS['wddtf_test_did_actions'][$hook] ?? 0);
}

function wp_doing_ajax(): bool {
    return true === ($GLOBALS['wddtf_test_is_ajax'] ?? false);
}

function is_admin(): bool {
    return true === ($GLOBALS['wddtf_test_is_admin'] ?? false);
}

function __(string $text, string $domain = 'default'): string {
    return $text;
}

function sanitize_text_field(string $value): string {
    return trim(strip_tags($value));
}

function sanitize_key(string $key): string {
    return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key) ?? '');
}

function wp_unslash(mixed $value): mixed {
    return $value;
}

function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false {
    return json_encode($value, $flags, $depth);
}

function is_wp_error(mixed $value): bool {
    return $value instanceof WP_Error;
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

function wp_salt(string $scheme = 'auth'): string {
    return (string) ($GLOBALS['wddtf_test_auth_salt'] ?? '');
}

function maybe_serialize(mixed $value): string {
    if ( is_array($value) || is_object($value) ) {
        return serialize($value);
    }

    return (string) $value;
}

function add_option(string $option, mixed $value = '', string $deprecated = '', bool|null $autoload = null): bool {
    if ( array_key_exists($option, $GLOBALS['wddtf_test_options']) ) {
        return false;
    }

    $GLOBALS['wddtf_test_options'][$option] = [
        'value'    => $value,
        'autoload' => $autoload,
    ];
    return true;
}

function get_option(string $option, mixed $default = false): mixed {
    return $GLOBALS['wddtf_test_options'][$option]['value'] ?? $default;
}

function delete_option(string $option): bool {
    if ( ! array_key_exists($option, $GLOBALS['wddtf_test_options']) ) {
        return false;
    }

    unset($GLOBALS['wddtf_test_options'][$option]);
    return true;
}

function wp_cache_delete(string $key, string $group = ''): bool {
    return true;
}

function set_transient(string $key, mixed $value, int $expiration = 0): bool {
    if ( in_array($key, $GLOBALS['wddtf_test_transient_failures'], true) ) {
        return false;
    }

    $GLOBALS['wddtf_test_transients'][$key] = [
        'value'      => $value,
        'expiration' => $expiration,
    ];
    return true;
}

function get_transient(string $key): mixed {
    return $GLOBALS['wddtf_test_transients'][$key]['value'] ?? false;
}

function delete_transient(string $key): bool {
    unset($GLOBALS['wddtf_test_transients'][$key]);
    return true;
}

function wp_schedule_single_event(int $timestamp, string $hook, array $args = [], bool $wp_error = false): bool|WP_Error {
    $GLOBALS['wddtf_test_schedule_calls'][] = [$timestamp, $hook, $args, $wp_error];
    $result = $GLOBALS['wddtf_test_schedule_result'];

    if ( $result instanceof WP_Error || false === $result ) {
        return $result;
    }

    $key = $hook . '|' . $timestamp . '|' . md5(serialize($args));
    $GLOBALS['wddtf_test_cron_events'][$key] = (object) [
        'hook'      => $hook,
        'timestamp' => $timestamp,
        'schedule'  => false,
        'args'      => $args,
    ];

    return true;
}

function wp_get_scheduled_event(string $hook, array $args = [], ?int $timestamp = null): object|false {
    if ( ! empty($GLOBALS['wddtf_test_hide_scheduled_events']) ) {
        return false;
    }

    foreach ( $GLOBALS['wddtf_test_cron_events'] as $event ) {
        if ( $event->hook !== $hook || $event->args !== $args ) {
            continue;
        }

        if ( null !== $timestamp && $event->timestamp !== $timestamp ) {
            continue;
        }

        return $event;
    }

    return false;
}

function wp_get_ready_cron_jobs(): array {
    return $GLOBALS['wddtf_test_ready_cron_jobs'];
}

require_once dirname(__DIR__) . '/src/Autoloader.php';
\WDDTF\Autoloader::register('WDDTF', dirname(__DIR__) . '/src');