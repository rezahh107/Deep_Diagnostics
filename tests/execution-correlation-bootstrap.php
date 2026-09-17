<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// ProviderEvidenceStore's compare-and-swap test path requires the repository's wpdb stub.
// The full suite happened to instantiate it in earlier tests; focused correlation coverage
// must establish its own dependency so it is deterministic and order-independent.
$GLOBALS['wpdb'] = new wpdb();
