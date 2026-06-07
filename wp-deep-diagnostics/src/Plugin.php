<?php
declare(strict_types=1);

namespace WDDTF;

use WDDTF\Admin\Admin_Page;
use WDDTF\Admin\Ajax_Controller;
use WDDTF\Diagnostics\Manager;
use WDDTF\Frontend\Frontend;
use WDDTF\Rest\Routes;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Plugin {
    private Manager $manager;

    public function boot(): void {
        $this->manager = new Manager();
        $this->manager->boot();

        add_action(
            'init',
            function(): void {
                ( new Admin_Page($this->manager) )->register();
                ( new Ajax_Controller($this->manager) )->register();
                ( new Routes($this->manager) )->register();
                ( new Frontend($this->manager) )->register();
            }
        );

        add_action('shutdown', [$this->manager, 'finalize'], 9999);

        register_shutdown_function(
            function(): void {
                if ( isset($this->manager) && ! $this->manager->isFinalized() ) {
                    $this->manager->finalize();
                }
            }
        );
    }
}
