<?php
declare(strict_types=1);

namespace WDDTF\Frontend;

use WDDTF\Diagnostics\Manager;
use WDDTF\Support\Input;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Frontend {
    public function __construct(private Manager $manager) {
    }

    public function register(): void {
        add_action(
            'template_redirect',
            function(): void {
                if ( is_admin() || wp_doing_ajax() || ( defined('REST_REQUEST') && REST_REQUEST ) ) {
                    return;
                }

                $ip  = Input::remoteAddr('');
                $key = 'wddtf_frontend_sampled_' . Input::hashedKeyFragment($ip);

                if ( ! get_transient($key) ) {
                    $this->manager->recordEvent(
                        'frontend',
                        [
                            'uri' => Input::requestUri(''),
                        ]
                    );

                    set_transient($key, 1, 300);
                }
            }
        );
    }
}
