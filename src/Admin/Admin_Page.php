<?php
declare(strict_types=1);

namespace WDDTF\Admin;

use WDDTF\Diagnostics\Manager;
use WDDTF\Providers\ProviderContract;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Admin_Page {
    private const CAPABILITY = 'manage_options';
    private const SLUG = 'wp-deep-diagnostics';
    private const CRON_ACTION = 'wddtf_run_cron_qualification';
    private const GRAVITYFLOW_INBOX_ACTION = 'wddtf_start_gravityflow_inbox_observation';
    private const PROVIDER_IMPORT_ACTION = 'wddtf_import_diagnostic_provider_bundle';
    private const PROVIDER_REFRESH_ACTION = 'wddtf_refresh_diagnostic_provider';
    private const PROVIDER_EXPORT_ACTION = 'wddtf_export_diagnostic_provider_evidence';

    public function __construct(private Manager $manager) {
    }

    public function register(): void {
        add_action('admin_menu', function(): void {
            add_management_page(__('Deep Diagnostics', 'wp-deep-diagnostics'), __('Deep Diagnostics', 'wp-deep-diagnostics'), self::CAPABILITY, self::SLUG, [$this, 'render']);
        });
        add_action('admin_enqueue_scripts', function(string $hookSuffix): void {
            if ( 'tools_page_' . self::SLUG !== $hookSuffix ) return;
            wp_enqueue_style('wddtf-admin', WDDTF_URL . 'assets/admin.css', [], WDDTF_VERSION);
        });
        add_action('admin_post_' . self::CRON_ACTION, [$this, 'runCronQualification']);
        add_action('admin_post_' . self::GRAVITYFLOW_INBOX_ACTION, [$this, 'startGravityFlowInboxObservation']);
        add_action('admin_post_' . self::PROVIDER_IMPORT_ACTION, [$this, 'importProviderBundle']);
        add_action('admin_post_' . self::PROVIDER_REFRESH_ACTION, [$this, 'refreshProvider']);
        add_action('admin_post_' . self::PROVIDER_EXPORT_ACTION, [$this, 'exportProviderEvidence']);
    }

    public function runCronQualification(): void {
        $this->authorize(self::CRON_ACTION, __('Cron qualification requires an explicit POST request.', 'wp-deep-diagnostics'));
        $result = $this->manager->startCronQualification();
        wp_safe_redirect(add_query_arg('wddtf_cron_action', sanitize_key((string) ($result['reason'] ?? 'unknown')), admin_url('tools.php?page=' . self::SLUG)));
        exit;
    }

    public function startGravityFlowInboxObservation(): void {
        $this->authorize(self::GRAVITYFLOW_INBOX_ACTION, __('Gravity diagnostic collection requires an explicit POST request.', 'wp-deep-diagnostics'));
        $result = $this->manager->startGravityDiagnostic();
        wp_safe_redirect(add_query_arg('wddtf_gravity_action', sanitize_key((string) ($result['reason'] ?? 'unknown')), admin_url('tools.php?page=' . self::SLUG)));
        exit;
    }

    public function importProviderBundle(): void {
        $this->authorize(self::PROVIDER_IMPORT_ACTION, __('Provider evidence import requires an explicit POST request.', 'wp-deep-diagnostics'));
        $file = $_FILES['wddtf_provider_bundle'] ?? null;
        if ( ! is_array($file) || UPLOAD_ERR_OK !== ($file['error'] ?? null) ) $this->redirectProviderAction('upload_error');
        $declaredSize = isset($file['size']) && is_numeric($file['size']) ? (int) $file['size'] : 0;
        if ( $declaredSize <= 0 ) $this->redirectProviderAction('empty_import');
        if ( $declaredSize > ProviderContract::MAX_IMPORT_BYTES ) $this->redirectProviderAction('import_too_large');
        $tmpName = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if ( '' === $tmpName || ! is_uploaded_file($tmpName) || ! is_readable($tmpName) ) $this->redirectProviderAction('upload_error');
        $raw = file_get_contents($tmpName, false, null, 0, ProviderContract::MAX_IMPORT_BYTES + 1);
        if ( false === $raw ) $this->redirectProviderAction('upload_error');
        $result = $this->manager->importGppSupportBundle($raw);
        $this->redirectProviderAction(sanitize_key((string) ($result['reason'] ?? 'import_error')));
    }

    public function refreshProvider(): void {
        $this->authorize(self::PROVIDER_REFRESH_ACTION, __('Direct provider refresh requires an explicit POST request.', 'wp-deep-diagnostics'));
        $providerKey = isset($_POST['provider_key']) && is_string($_POST['provider_key']) ? sanitize_key(wp_unslash($_POST['provider_key'])) : '';
        $result = $this->manager->refreshDiagnosticProvider($providerKey);
        $this->redirectProviderAction(sanitize_key((string) ($result['reason'] ?? 'direct_provider_error')));
    }

    public function exportProviderEvidence(): void {
        $this->authorize(self::PROVIDER_EXPORT_ACTION, __('Provider evidence export requires an explicit POST request.', 'wp-deep-diagnostics'));
        $providerKey = isset($_POST['provider_key']) && is_string($_POST['provider_key']) ? sanitize_key(wp_unslash($_POST['provider_key'])) : '';
        $json = $this->manager->exportProviderEvidence($providerKey);
        if ( null === $json ) wp_die(esc_html__('No compatible provider evidence is available to export.', 'wp-deep-diagnostics'));
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="deep-diagnostics-provider-' . $providerKey . '.json"');
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- normalized JSON attachment.
        exit;
    }

    public function render(): void {
        if ( ! current_user_can(self::CAPABILITY) ) wp_die(esc_html__('Access denied', 'wp-deep-diagnostics'));
        $report = $this->manager->getLastReport();
        $cron = $this->manager->getCronDiagnostics();
        $gravity = $this->manager->getGravityDiagnostics();
        $providerDiagnostics = $this->manager->getProviderDiagnostics('gpp');
        $cronAction = isset($_GET['wddtf_cron_action']) && is_string($_GET['wddtf_cron_action']) ? sanitize_key(wp_unslash($_GET['wddtf_cron_action'])) : '';
        $gravityAction = isset($_GET['wddtf_gravity_action']) && is_string($_GET['wddtf_gravity_action']) ? sanitize_key(wp_unslash($_GET['wddtf_gravity_action'])) : '';
        $providerAction = isset($_GET['wddtf_provider_action']) && is_string($_GET['wddtf_provider_action']) ? sanitize_key(wp_unslash($_GET['wddtf_provider_action'])) : '';

        ob_start();
        require WDDTF_PATH . 'templates/provider-diagnostics.php';
        $providerHtml = (string) ob_get_clean();
        ob_start();
        require WDDTF_PATH . 'templates/admin-page.php';
        $pageHtml = (string) ob_get_clean();
        $headingEnd = strpos($pageHtml, '</h1>');
        if ( false === $headingEnd ) {
            echo $providerHtml . $pageHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both templates escape at output boundaries.
            return;
        }
        $headingEnd += strlen('</h1>');
        echo substr($pageHtml, 0, $headingEnd) . $providerHtml . substr($pageHtml, $headingEnd); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function redirectProviderAction(string $reason): never {
        wp_safe_redirect(add_query_arg('wddtf_provider_action', sanitize_key($reason), admin_url('tools.php?page=' . self::SLUG . '#wddtf-gpp-diagnostics')));
        exit;
    }

    private function authorize(string $action, string $postMessage): void {
        if ( ! current_user_can(self::CAPABILITY) ) wp_die(esc_html__('Access denied', 'wp-deep-diagnostics'));
        $this->requirePost($action, $postMessage);
    }

    private function requirePost(string $action, string $message): void {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        if ( 'POST' !== $method ) wp_die(esc_html($message));
        check_admin_referer($action);
    }
}
