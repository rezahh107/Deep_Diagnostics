<?php
declare(strict_types=1);

namespace WDDTF\Collectors;

use WDDTF\Support\Env;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class SystemInspector {
    public function snapshot(): array {
        $data = [
            'php_version'        => PHP_VERSION,
            'wp_version'         => get_bloginfo('version'),
            'memory_limit'       => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'opcache'            => Env::opcache(),
            'autoload_count'     => 0,
            'autoload_size'      => 0,
            'heavy_autoload'     => [],
        ];

        $options = wp_load_alloptions();

        if ( is_array($options) ) {
            $data['autoload_count'] = count($options);

            foreach ( $options as $value ) {
                $data['autoload_size'] += strlen(maybe_serialize($value));
            }
        }

        global $wpdb;

        if ( isset($wpdb) && $wpdb instanceof \wpdb ) {
            // WordPress 6.6+ may use dynamic autoload markers in addition to legacy "yes".
            $autoloadValues = ['yes', 'on', 'auto-on', 'auto'];
            $placeholders   = implode(',', array_fill(0, count($autoloadValues), '%s'));
            $heavy = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload IN ($placeholders) ORDER BY size DESC LIMIT 10",
                    $autoloadValues
                ),
                ARRAY_A
            );

            $data['heavy_autoload'] = is_array($heavy) ? $heavy : [];
        }

        return $data;
    }
}
