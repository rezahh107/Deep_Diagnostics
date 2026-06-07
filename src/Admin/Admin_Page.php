<?php
declare(strict_types=1);

namespace WDDTF\Admin;

use WDDTF\Diagnostics\Manager;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Admin_Page {
    public function __construct(private Manager $manager) {
    }

    public function register(): void {
        add_action(
            'admin_menu',
            function(): void {
                add_management_page(
                    __('Deep Diagnostics', 'wp-deep-diagnostics'),
                    __('Deep Diagnostics', 'wp-deep-diagnostics'),
                    'manage_options',
                    'wp-deep-diagnostics',
                    [$this, 'render']
                );
            }
        );

        add_action(
            'admin_enqueue_scripts',
            function(string $hookSuffix): void {
                if ( 'tools_page_wp-deep-diagnostics' !== $hookSuffix ) {
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
    }

    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('Access denied', 'wp-deep-diagnostics'));
        }

        $report = $this->manager->getLastReport();

        require WDDTF_PATH . 'templates/admin-page.php';
    }
}
