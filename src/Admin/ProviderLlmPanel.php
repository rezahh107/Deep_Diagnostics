<?php
declare(strict_types=1);

namespace WDDTF\Admin;

use WDDTF\Diagnostics\Manager;
use WDDTF\Providers\ProviderLlmExport;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class ProviderLlmPanel {
    private const CAPABILITY = 'manage_options';
    private const ACTION = 'wddtf_export_diagnostic_provider_llm';
    private const PROVIDER_KEY = 'gpp';

    public function __construct(
        private Manager $manager,
        private ?ProviderLlmExport $exporter = null
    ) {
        $this->exporter ??= new ProviderLlmExport();
    }

    public function register(): void {
        add_action(
            'admin_enqueue_scripts',
            function(string $hookSuffix): void {
                if ( 'tools_page_wp-deep-diagnostics' !== $hookSuffix ) {
                    return;
                }
                wp_enqueue_script(
                    'wddtf-admin',
                    WDDTF_URL . 'assets/admin.js',
                    [],
                    WDDTF_VERSION,
                    true
                );
            }
        );
        add_action('admin_footer-tools_page_wp-deep-diagnostics', [$this, 'render']);
        add_action('admin_post_' . self::ACTION, [$this, 'export']);
    }

    public function render(): void {
        if ( ! current_user_can(self::CAPABILITY) ) {
            return;
        }

        $diagnostics = $this->manager->getProviderDiagnostics(self::PROVIDER_KEY);
        $report = $this->exporter->build($diagnostics);
        if ( null === $report ) {
            return;
        }
        $markdown = $this->exporter->markdown($report);
        ?>
        <div id="wddtf-provider-llm-export" class="wddtf-provider-llm-export" hidden>
            <h3><?php esc_html_e('Language-model handoff', 'wp-deep-diagnostics'); ?></h3>
            <p><?php esc_html_e('Create a bounded report from the same normalized provider evidence used by Deep Diagnostics. It includes source/runtime context, grouped current findings, historical incidents, privacy declarations, and claim boundaries. The original uploaded filename is intentionally not retained.', 'wp-deep-diagnostics'); ?></p>
            <button type="button" class="button button-secondary" data-wddtf-toggle-target="wddtf-provider-llm-panel" aria-controls="wddtf-provider-llm-panel" aria-expanded="false"><?php esc_html_e('Report for language model', 'wp-deep-diagnostics'); ?></button>
            <div id="wddtf-provider-llm-panel" class="wddtf-llm-panel" hidden>
                <p class="description"><?php esc_html_e('The report is generated only from normalized privacy-safe Provider evidence. Surface grouping is a presentation aid; an empty group is not proof that the surface is healthy.', 'wp-deep-diagnostics'); ?></p>
                <textarea id="wddtf-provider-llm-markdown" readonly rows="24" class="large-text code wddtf-code-output" dir="ltr"><?php echo esc_textarea($markdown); ?></textarea>
                <div class="wddtf-inline-actions">
                    <button type="button" class="button" data-wddtf-copy-target="wddtf-provider-llm-markdown" data-wddtf-copied-label="<?php echo esc_attr__('Copied', 'wp-deep-diagnostics'); ?>" data-wddtf-copy-failed-label="<?php echo esc_attr__('Copy failed', 'wp-deep-diagnostics'); ?>"><?php esc_html_e('Copy report', 'wp-deep-diagnostics'); ?></button>
                    <?php $this->downloadForm('markdown', __('Download Markdown', 'wp-deep-diagnostics')); ?>
                    <?php $this->downloadForm('json', __('Download JSON', 'wp-deep-diagnostics')); ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function export(): void {
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(esc_html__('Access denied', 'wp-deep-diagnostics'));
        }
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : '';
        if ( 'POST' !== $method ) {
            wp_die(esc_html__('Provider language-model export requires an explicit POST request.', 'wp-deep-diagnostics'));
        }
        check_admin_referer(self::ACTION);

        $providerKey = isset($_POST['provider_key']) && is_string($_POST['provider_key'])
            ? sanitize_key(wp_unslash($_POST['provider_key']))
            : '';
        if ( self::PROVIDER_KEY !== $providerKey ) {
            wp_die(esc_html__('Unsupported provider language-model export target.', 'wp-deep-diagnostics'));
        }
        $format = isset($_POST['format']) && is_string($_POST['format'])
            ? sanitize_key(wp_unslash($_POST['format']))
            : '';
        if ( ! in_array($format, ['json', 'markdown'], true) ) {
            wp_die(esc_html__('Unsupported provider language-model export format.', 'wp-deep-diagnostics'));
        }

        $diagnostics = $this->manager->getProviderDiagnostics($providerKey);
        $report = $this->exporter->build($diagnostics);
        if ( null === $report ) {
            wp_die(esc_html__('No compatible provider evidence is available for a language-model report.', 'wp-deep-diagnostics'));
        }

        if ( 'json' === $format ) {
            $content = $this->exporter->json($report);
            $contentType = 'application/json';
            $extension = 'json';
        } else {
            $content = $this->exporter->markdown($report);
            $contentType = 'text/markdown';
            $extension = 'md';
        }
        if ( ! is_string($content) || '' === $content ) {
            wp_die(esc_html__('Deep Diagnostics could not build the provider language-model report.', 'wp-deep-diagnostics'));
        }

        nocache_headers();
        header('Content-Type: ' . $contentType . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="deep-diagnostics-provider-' . $providerKey . '-llm.' . $extension . '"');
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bounded normalized diagnostic attachment.
        exit;
    }

    private function downloadForm(string $format, string $label): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wddtf-action-form">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="provider_key" value="<?php echo esc_attr(self::PROVIDER_KEY); ?>">
            <input type="hidden" name="format" value="<?php echo esc_attr($format); ?>">
            <?php wp_nonce_field(self::ACTION); ?>
            <?php submit_button($label, 'secondary', 'submit', false); ?>
        </form>
        <?php
    }
}
