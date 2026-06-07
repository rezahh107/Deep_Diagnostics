<?php
declare(strict_types=1);

if ( ! defined('WP_UNINSTALL_PLUGIN') ) {
    exit;
}

delete_transient('wddtf_last_report');
delete_transient('wddtf_last_report_json');
delete_transient('wddtf_last_report_md');

$upload = wp_upload_dir();
$dir    = trailingslashit($upload['basedir']) . 'wp-deep-diagnostics/';

if ( is_dir($dir) ) {
    $files = array_merge(glob($dir . '*'), glob($dir . '.*'));
    foreach ( $files as $file ) {
        $basename = basename($file);
        if ( $basename === '.' || $basename === '..' ) {
            continue;
        }
        if ( is_file($file) ) {
            @unlink($file);
        }
    }

    @rmdir($dir);
}
