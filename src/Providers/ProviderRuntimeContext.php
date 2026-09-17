<?php
declare(strict_types=1);

namespace WDDTF\Providers;

use WDDTF\Diagnostics\ExecutionCorrelationContext;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Public read-only same-request integration seam for cooperating diagnostic providers.
 */
final class ProviderRuntimeContext {
    private function __construct() {
    }

    public static function currentExecutionCorrelationRef(): ?string {
        return ExecutionCorrelationContext::activeReference();
    }
}
