<?php
declare(strict_types=1);

if ( ! defined('WP_UNINSTALL_PLUGIN') ) {
    exit;
}

delete_transient('wddtf_last_report');
delete_transient('wddtf_last_report_json');
delete_transient('wddtf_last_report_md');

$currentSession = get_transient('wddtf_cron_qualification_current');
if ( is_string($currentSession) && 1 === preg_match('/^ds-[a-f0-9]{16}$/', $currentSession) ) {
    delete_transient('wddtf_diag_session_' . substr(hash('sha256', $currentSession), 0, 32));
}
delete_transient('wddtf_cron_qualification_current');
wp_clear_scheduled_hook('wddtf_cron_qualification_probe');

$upload = wp_upload_dir();
$dir    = trailingslashit($upload['basedir']) . 'wp-deep-diagnostics/';

if ( is_dir($dir) ) {
    $files = array_merge(glob($dir . '*') ?: [], glob($dir . '.*') ?: []);

    foreach ( $files as $file ) {
        $basename = basename($file);
        if ( '.' === $basename || '..' === $basename ) {
            continue;
        }

        if ( is_file($file) ) {
            @unlink($file);
        }
    }

    @rmdir($dir);
}
