<?php
declare(strict_types=1);

namespace WDDTF\Providers;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class DirectSnapshotAdapter {
    public function __construct(private ?EvidenceSanitizer $sanitizer = null) {
        $this->sanitizer ??= new EvidenceSanitizer();
    }

    public function normalize(array $registration, mixed $payload): array {
        if ( ! is_array($payload) ) {
            throw new ProviderImportException('direct_snapshot_invalid', __('The direct diagnostic provider returned an invalid snapshot.', 'wp-deep-diagnostics'));
        }
        if ( ($registration['schema_version'] ?? null) !== ($payload['schema_version'] ?? null) ) {
            throw new ProviderImportException('direct_schema_mismatch', __('The direct diagnostic provider returned a snapshot schema that does not match its registration.', 'wp-deep-diagnostics'));
        }

        $components = [];
        if ( is_array($payload['components'] ?? null) ) {
            foreach ( array_slice($payload['components'], 0, ProviderContract::MAX_COMPONENTS) as $component ) {
                if ( ! is_array($component) ) continue;
                $key = $this->sanitizer->token($component['key'] ?? null, 128);
                $status = $this->sanitizer->token($component['status'] ?? null, 64);
                if ( null !== $key && null !== $status ) {
                    $components[] = ['key' => $key, 'status' => $status];
                }
            }
        }

        $unresolved = [];
        if ( is_array($payload['unresolved'] ?? null) ) {
            foreach ( array_slice($payload['unresolved'], 0, ProviderContract::MAX_UNRESOLVED) as $fact ) {
                if ( ! is_array($fact) ) continue;
                $kind = $this->sanitizer->token($fact['kind'] ?? null, 64);
                $key = $this->sanitizer->token($fact['key'] ?? null, 128);
                $state = $this->sanitizer->token($fact['state'] ?? null, 64);
                if ( null !== $kind && null !== $key && null !== $state ) {
                    $unresolved[] = [
                        'kind' => $kind,
                        'key' => $key,
                        'state' => $state,
                        'status' => $this->sanitizer->token($fact['status'] ?? null, 64),
                        'reason_code' => $this->sanitizer->token($fact['reason_code'] ?? null, 96),
                    ];
                }
            }
        }

        $environment = [];
        if ( is_array($payload['environment'] ?? null) ) {
            foreach ( array_slice($payload['environment'], 0, 20, true) as $key => $value ) {
                $safeKey = is_string($key) ? sanitize_key($key) : '';
                $safeValue = $this->sanitizer->version($value);
                if ( '' !== $safeKey && $safeKey === $key && null !== $safeValue ) {
                    $environment[$safeKey] = $safeValue;
                }
            }
            ksort($environment, SORT_STRING);
        }

        $currentStatus = $this->sanitizer->token($payload['current_status'] ?? null, 64) ?? (empty($unresolved) ? 'no_unresolved_observed' : 'attention');

        return [
            'model_version' => ProviderContract::NORMALIZED_SCHEMA_VERSION,
            'provider' => [
                'key' => $registration['provider_key'],
                'name' => $registration['name'],
                'version' => $registration['provider_version'],
                'schema_version' => $registration['schema_version'],
                'capabilities' => $registration['capabilities'],
            ],
            'source' => [
                'mode' => 'direct',
                'bundle_type' => null,
                'schema_version' => $registration['schema_version'],
                'observed_at_utc' => $this->sanitizer->timestamp($payload['generated_at_utc'] ?? null),
            ],
            'environment' => $environment,
            'current' => ['status' => $currentStatus, 'components' => $components],
            'unresolved' => $unresolved,
            'incidents' => $this->sanitizer->incidents($payload['incidents'] ?? []),
            'recent_success' => $this->sanitizer->recentSuccess($payload['recent_success'] ?? []),
            'privacy_boundary' => $this->sanitizer->privacyBoundary($payload['privacy_boundary'] ?? []),
            'correlation_ref' => $this->sanitizer->token($payload['correlation_ref'] ?? null, 128),
            'claim_ceiling' => $this->sanitizer->token($payload['claim_ceiling'] ?? null, 96) ?? 'provider_declared_evidence_only',
        ];
    }
}
