<?php
declare(strict_types=1);

namespace WDDTF\Providers;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class ProviderImportException extends \RuntimeException {
    public function __construct(private string $reason, string $message) {
        parent::__construct($message);
    }

    public function reason(): string {
        return $this->reason;
    }
}
