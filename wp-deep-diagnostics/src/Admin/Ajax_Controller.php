<?php
declare(strict_types=1);

namespace WDDTF\Admin;

use WDDTF\Diagnostics\Manager;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Ajax_Controller {
    public function __construct(private Manager $manager) {
    }

    public function register(): void {
        add_action(
            'wp_ajax_wddtf_get_report',
            function(): void {
                if ( ! current_user_can('manage_options') ) {
                    wp_send_json_error('forbidden', 403);
                }

                check_ajax_referer('wddtf_nonce', 'nonce');
                wp_send_json_success($this->manager->getLastReport());
            }
        );
    }
}
