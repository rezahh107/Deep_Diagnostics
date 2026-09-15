<?php
declare(strict_types=1);

namespace WDDTF\Admin;

use WDDTF\Diagnostics\Manager;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Admin_Page {
    private const CAPABILITY = 'manage_options';
    private const SLUG = 'wp-deep-diagnostics';
    private const CRON_ACTION = 'wddtf_run_cron_qualification';

    public function __construct(private Manager $manager) {
    }

    public function register(): void {
        add_action(
            'admin_menu',
            function(): void {
                add_management_page(
                    __('Deep Diagnostics', 'wp-deep-diagnostics'),
                    __('Deep Diagnostics', 'wp-deep-diagnostics'),
                    self::CAPABILITY,
                    self::SLUG,
                    [$this, 'render']
                );
            }
        );

        add_action(
            'admin_enqueue_scripts',
            function(string $hookSuffix): void {
                if ( 'tools_page_' . self::SLUG !== $hookSuffix ) {
                    return;
                }

                wp_enqueue_style(
                    'wddtf-admin',
                    WDDTF_URL . 'assets/admin.css',
                    [],
                    WDDTF_VERSION
                );
            }
        );

        add_action('admin_post_' . self::CRON_ACTION, [$this, 'runCronQualification']);
    }

    public function runCronQualification(): void {
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(esc_html__('Access denied', 'wp-deep-diagnostics'));
        }

        check_admin_referer(self::CRON_ACTION);
        $result = $this->manager->startCronQualification();
        $reason = sanitize_key((string) ($result['reason'] ?? 'unknown'));

        wp_safe_redirect(
            add_query_arg(
                'wddtf_cron_action',
                $reason,
                admin_url('tools.php?page=' . self::SLUG)
            )
        );
        exit;
    }

    public function render(): void {
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(esc_html__('Access denied', 'wp-deep-diagnostics'));
        }

        $report = $this->manager->getLastReport();
        $cron = $this->manager->getCronDiagnostics();
        $cronAction = '';

        if ( isset($_GET['wddtf_cron_action']) && is_string($_GET['wddtf_cron_action']) ) {
            $cronAction = sanitize_key(wp_unslash($_GET['wddtf_cron_action']));
        }

        require WDDTF_PATH . 'templates/admin-page.php';
    }
}
