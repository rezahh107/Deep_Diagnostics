<?php
/**
 * CI-only real WordPress request-lifecycle probe for execution-correlation exposure.
 */

declare(strict_types=1);

use WDDTF\Providers\ProviderRuntimeContext;

if ( ! defined('ABSPATH') ) {
    exit;
}

$wddtfCiCurrentRef = static fn(): ?string => ProviderRuntimeContext::currentExecutionCorrelationRef();

add_action(
    'rest_api_init',
    static function() use ($wddtfCiCurrentRef): void {
        register_rest_route(
            'wddtf-ci/v1',
            '/correlation',
            [
                'methods' => 'GET',
                'permission_callback' => '__return_true',
                'callback' => static fn(): \WP_REST_Response => rest_ensure_response([
                    'ref' => $wddtfCiCurrentRef(),
                ]),
            ]
        );
    }
);

add_action(
    'template_redirect',
    static function() use ($wddtfCiCurrentRef): void {
        if ( ! isset($_GET['wddtf_ci_front']) ) {
            return;
        }

        $ref = $wddtfCiCurrentRef();
        update_option('wddtf_ci_front_ref', $ref ?? 'NULL', false);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(['ref' => $ref]);
        exit;
    },
    PHP_INT_MAX
);

$wddtfCiAdminPost = static function() use ($wddtfCiCurrentRef): void {
    $ref = $wddtfCiCurrentRef();
    update_option('wddtf_ci_admin_post_ref', $ref ?? 'NULL', false);
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode(['ref' => $ref]);
    exit;
};
add_action('admin_post_wddtf_ci_correlation', $wddtfCiAdminPost);
add_action('admin_post_nopriv_wddtf_ci_correlation', $wddtfCiAdminPost);

$wddtfCiAjax = static function() use ($wddtfCiCurrentRef): void {
    wp_send_json_success(['ref' => $wddtfCiCurrentRef()]);
};
add_action('wp_ajax_wddtf_ci_correlation', $wddtfCiAjax);
add_action('wp_ajax_nopriv_wddtf_ci_correlation', $wddtfCiAjax);

add_action(
    'wddtf_ci_cron_correlation',
    static function() use ($wddtfCiCurrentRef): void {
        $ref = $wddtfCiCurrentRef();
        update_option('wddtf_ci_cron_ref', $ref ?? 'NULL', false);
    }
);
