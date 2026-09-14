<?php
declare(strict_types=1);

namespace WDDTF\Logging;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class File_Logger {
    public function dir(): string {
        $upload = wp_upload_dir();

        if ( ! empty($upload['error']) ) {
            error_log('WDDTF: wp_upload_dir() error: ' . $upload['error']);
            return '';
        }

        $base = trailingslashit($upload['basedir']) . 'wp-deep-diagnostics/';

        if ( ! file_exists($base) ) {
            wp_mkdir_p($base);
            file_put_contents($base . 'index.php', '<?php // silence');
            file_put_contents($base . '.htaccess', 'Deny from all');
        }

        return $base;
    }

    public function saveJson(array $payload): string {
        $dir = $this->dir();
        if ( '' === $dir ) {
            return '';
        }

        $this->cleanup($dir, 30);

        $path   = $dir . $this->buildFilename('json');
        $result = file_put_contents(
            $path,
            wp_json_encode(
                $payload,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
            ),
            LOCK_EX
        );

        if ( false === $result ) {
            error_log('WDDTF: Failed to write JSON report to ' . $path);
            return '';
        }

        return $path;
    }

    public function saveMarkdown(string $markdown): string {
        $dir = $this->dir();
        if ( '' === $dir ) {
            return '';
        }

        $this->cleanup($dir, 30);

        $path   = $dir . $this->buildFilename('md');
        $result = file_put_contents($path, $markdown, LOCK_EX);

        if ( false === $result ) {
            error_log('WDDTF: Failed to write Markdown report to ' . $path);
            return '';
        }

        return $path;
    }

    private function cleanup(string $dir, int $maxAgeDays): void {
        if ( ! is_dir($dir) ) {
            return;
        }

        $cutoff = time() - ($maxAgeDays * DAY_IN_SECONDS);
        $files  = array_merge(glob($dir . 'report-*') ?: [], glob($dir . 'log-*') ?: []);

        foreach ( $files as $file ) {
            if ( ! is_file($file) ) {
                continue;
            }

            $mtime = filemtime($file);
            if ( false !== $mtime && $mtime < $cutoff ) {
                @unlink($file);
            }
        }
    }

    private function buildFilename(string $extension): string {
        $microtime = microtime(true);
        $seconds   = gmdate('Y-m-d-His', (int) $microtime);
        $micros    = sprintf('%06d', (int) (($microtime - (int) $microtime) * 1000000));
        $random    = wp_generate_password(6, false, false);

        return sprintf('report-%1$s-%2$s-%3$s.%4$s', $seconds, $micros, strtolower($random), $extension);
    }
}
