<?php
declare(strict_types=1);

namespace WDDTF\Logging;

if ( ! defined('ABSPATH') ) {
    exit;
}

enum Severity: string {
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
