<?php
declare(strict_types=1);

namespace WDDTF\Rest;

use WDDTF\Diagnostics\Manager;
use WDDTF\Support\Input;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Routes {
    public function __construct(private Manager $manager) {
    }

    public function register(): void {
        add_action(
            'rest_api_init',
            function(): void {
                register_rest_route(
                    'wp-deep-diagnostics/v1',
                    '/report',
                    [
                        'methods'             => 'GET',
                        'permission_callback' => function(): bool {
                            $capability = apply_filters('wddtf_rest_capability', 'manage_options');
                            return current_user_can($capability);
                        },
                        'callback'            => function(\WP_REST_Request $request) {
                            $ip  = Input::remoteAddr('unknown');
                            $key = 'wddtf_rate_' . Input::hashedKeyFragment($ip);

                            if ( get_transient($key) ) {
                                return new \WP_Error(
                                    'rate_limited',
                                    __('Too many requests. Wait a moment.', 'wp-deep-diagnostics'),
                                    ['status' => 429]
                                );
                            }

                            set_transient($key, 1, 5);

                            return $this->manager->getLastReport();
                        },
                    ]
                );
            }
        );
    }
}
