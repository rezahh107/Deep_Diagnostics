<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap-cas.php';

// ProviderEvidenceStore's compare-and-swap path needs the repository's CAS-capable wpdb
// stub even when ExecutionCorrelationTest runs alone. The full suite must not be relied on
// to supply test-order side effects.
$GLOBALS['wpdb'] = new wpdb();
