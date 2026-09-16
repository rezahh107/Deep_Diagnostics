<?php
declare(strict_types=1);

namespace WDDTF\Providers;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class ProviderContract {
    public const CONTRACT_VERSION = '1.0.0';
    public const NORMALIZED_SCHEMA_VERSION = '1.0.0';
    public const REGISTRATION_FILTER = 'wddtf_diagnostic_providers_v1';
    public const STORE_OPTION = 'wddtf_provider_evidence_v1';
    public const MAX_IMPORT_BYTES = 262144;
    public const MAX_HISTORY_PER_PROVIDER = 5;
    public const MAX_COMPONENTS = 50;
    public const MAX_UNRESOLVED = 100;
    public const MAX_INCIDENTS = 25;
    public const MAX_INCIDENT_EVENTS = 40;
    public const MAX_RECENT_SUCCESS = 12;

    private function __construct() {
    }
}
