<?php
declare(strict_types=1);

namespace WDDTF\Support;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Input {
    public static function remoteAddr(string $default = 'unknown'): string {
        $raw = $_SERVER['REMOTE_ADDR'] ?? $default;

        if ( ! is_string($raw) || '' === $raw ) {
            $raw = $default;
        }

        return sanitize_text_field(wp_unslash($raw));
    }

    public static function requestUri(string $default = ''): string {
        $raw = $_SERVER['REQUEST_URI'] ?? $default;

        if ( ! is_string($raw) ) {
            $raw = $default;
        }

        return sanitize_text_field(wp_unslash($raw));
    }

    public static function hashedKeyFragment(string $value): string {
        return md5($value);
    }
}
