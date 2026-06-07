<?php
declare(strict_types=1);

namespace WDDTF\Logging;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Log_Entry {
    public function __construct(
        public string $layer,
        public string $key,
        public mixed $value,
        public Severity $severity,
        public float $timestamp,
    ) {
    }
}
