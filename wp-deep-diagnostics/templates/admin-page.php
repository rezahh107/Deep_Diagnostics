<?php
if ( ! defined('ABSPATH') ) {
    exit;
}
?>
<div class="wrap wddtf-wrap">
    <h1><?php esc_html_e('WP Deep Diagnostics', 'wp-deep-diagnostics'); ?></h1>

    <?php if ( empty($report) ) : ?>
        <p><?php esc_html_e('No report yet. Load any admin page and refresh.', 'wp-deep-diagnostics'); ?></p>
    <?php else : ?>
        <?php
        $queries_warning = $report['layers']['database']['warning'] ?? '';
        if ( $queries_warning ) :
            ?>
            <div class="notice notice-warning inline" style="margin:12px 0;">
                <p>
                    <?php echo esc_html($queries_warning); ?>
                    <?php esc_html_e(' Add define( "SAVEQUERIES", true ) to wp-config.php to enable precise query timing.', 'wp-deep-diagnostics'); ?>
                </p>
            </div>
        <?php endif; ?>

        <p>
            <?php esc_html_e('Top bottleneck', 'wp-deep-diagnostics'); ?>:
            <?php echo esc_html($report['bottlenecks'][0]['name'] ?? 'n/a'); ?>
        </p>

        <textarea readonly rows="20" class="large-text code" style="font-family:monospace;"><?php
            echo esc_textarea(
                wp_json_encode(
                    $report['llm_bundle'] ?? [],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
        ?></textarea>
    <?php endif; ?>
</div>
