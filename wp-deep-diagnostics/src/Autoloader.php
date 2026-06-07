<?php
declare(strict_types=1);

namespace WDDTF;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Autoloader {
    public static function register(string $prefix, string $base): void {
        spl_autoload_register(
            static function(string $class) use ($prefix, $base): void {
                $len = strlen($prefix);

                if ( 0 !== strncmp($prefix, $class, $len) ) {
                    return;
                }

                $relative = ltrim(substr($class, $len), '\\/');
                $file     = rtrim($base, '/\\') . '/' . str_replace('\\', '/', $relative) . '.php';

                if ( file_exists($file) ) {
                    require_once $file;
                }
            }
        );
    }
}
