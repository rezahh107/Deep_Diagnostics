<?php
declare(strict_types=1);

namespace WDDTF\Support;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Env {
    public static function bytesToHuman(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size  = (float) $bytes;
        $unit  = 0;

        while ( $size >= 1024 && $unit < 3 ) {
            $size /= 1024;
            ++$unit;
        }

        return rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.') . ' ' . $units[$unit];
    }

    public static function activeTheme(): array {
        $theme = wp_get_theme();

        return [
            'name'       => $theme->get('Name'),
            'template'   => $theme->get_template(),
            'stylesheet' => $theme->get_stylesheet(),
            'version'    => $theme->get('Version'),
        ];
    }

    public static function opcache(): array {
        if ( ! function_exists('opcache_get_status') ) {
            return ['enabled' => false];
        }

        $status = @opcache_get_status(false);

        if ( ! is_array($status) ) {
            return ['enabled' => false];
        }

        return [
            'enabled'    => true,
            'hit_rate'   => $status['opcache_statistics']['opcache_hit_rate'] ?? null,
            'mem_used'   => $status['memory_usage']['used_memory'] ?? null,
            'mem_free'   => $status['memory_usage']['free_memory'] ?? null,
            'cache_full' => ! empty($status['opcache_statistics']['cache_full']),
            'num_cached' => $status['opcache_statistics']['num_cached_scripts'] ?? null,
        ];
    }
}
