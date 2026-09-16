<?php
declare(strict_types=1);

namespace WDDTF\Providers;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class GppSupportBundleAdapter {
    public const BUNDLE_TYPE = 'gpp.support_bundle';
    public const SCHEMA_VERSION = '1.0.0';

    public function __construct(private ?EvidenceSanitizer $sanitizer = null) {
        $this->sanitizer ??= new EvidenceSanitizer();
    }

    public function normalize(array $bundle): array {
        if ( self::BUNDLE_TYPE !== ($bundle['bundle_type'] ?? null) ) {
            throw new ProviderImportException('unsupported_bundle_type', __('This file is not a compatible GPP Support Bundle.', 'wp-deep-diagnostics'));
        }
        if ( self::SCHEMA_VERSION !== ($bundle['schema_version'] ?? null) ) {
            throw new ProviderImportException('unsupported_schema_version', __('This GPP Support Bundle schema version is not supported by this Deep Diagnostics build.', 'wp-deep-diagnostics'));
        }

        $observedAt = $this->sanitizer->timestamp($bundle['generated_at_utc'] ?? null);
        if ( null === $observedAt ) {
            throw new ProviderImportException('invalid_source_timestamp', __('This GPP Support Bundle does not contain a valid source timestamp.', 'wp-deep-diagnostics'));
        }

        $observed = is_array($bundle['observed'] ?? null) ? $bundle['observed'] : [];
        $gpp = is_array($observed['gpp'] ?? null) ? $observed['gpp'] : [];
        $runtime = is_array($observed['runtime'] ?? null) ? $observed['runtime'] : [];
        $bindingHealth = is_array($observed['binding_health'] ?? null) ? $observed['binding_health'] : [];
        $diagnostics = is_array($observed['diagnostics'] ?? null) ? $observed['diagnostics'] : [];

        $components = $this->components($observed['active_profiles'] ?? []);
        $unresolved = $this->unresolvedFacts($bindingHealth, $bundle['unknown_or_unproven'] ?? []);
        $environment = [];
        foreach ( ['wordpress_version', 'php_version', 'gravity_forms_version', 'gravity_flow_version'] as $key ) {
            $value = $this->sanitizer->version($runtime[$key] ?? null);
            if ( null !== $value ) {
                $environment[$key] = $value;
            }
        }

        return [
            'model_version' => ProviderContract::NORMALIZED_SCHEMA_VERSION,
            'provider' => [
                'key' => 'gpp',
                'name' => 'Gravity Presentation Profiles',
                'version' => $this->sanitizer->version($gpp['version'] ?? null),
                'schema_version' => self::SCHEMA_VERSION,
                'capabilities' => ['support_bundle', 'binding_health', 'runtime_incidents', 'active_profiles'],
            ],
            'source' => [
                'mode' => 'support_bundle',
                'bundle_type' => self::BUNDLE_TYPE,
                'schema_version' => self::SCHEMA_VERSION,
                'observed_at_utc' => $observedAt,
            ],
            'environment' => $environment,
            'current' => [
                'status' => empty($unresolved) ? 'no_unresolved_observed' : 'attention',
                'components' => $components,
            ],
            'unresolved' => $unresolved,
            'incidents' => $this->sanitizer->incidents($diagnostics['recent_incidents'] ?? []),
            'recent_success' => $this->sanitizer->recentSuccess($diagnostics['recent_success'] ?? []),
            'privacy_boundary' => $this->sanitizer->privacyBoundary($bundle['privacy_boundary'] ?? []),
            'correlation_ref' => null,
            'claim_ceiling' => 'support_bundle_snapshot_only',
        ];
    }

    private function components(mixed $profiles): array {
        if ( ! is_array($profiles) ) {
            return [];
        }
        $components = [];
        foreach ( array_slice($profiles, 0, ProviderContract::MAX_COMPONENTS) as $profile ) {
            if ( ! is_array($profile) ) {
                continue;
            }
            $surface = $this->sanitizer->token($profile['surface'] ?? null, 96);
            if ( null === $surface ) {
                continue;
            }
            $components[] = [
                'key' => $surface,
                'status' => 'active',
                'package_id' => $this->sanitizer->token($profile['package_id'] ?? null, 128),
                'package_version' => $this->sanitizer->version($profile['package_version'] ?? null),
                'profile_id' => $this->sanitizer->token($profile['profile_id'] ?? null, 128),
                'artifact_type' => $this->sanitizer->token($profile['artifact_type'] ?? null, 96),
                'schema_version' => $this->sanitizer->version($profile['schema_version'] ?? null),
            ];
        }
        return $components;
    }

    private function unresolvedFacts(array $bindingHealth, mixed $topLevelUnknown): array {
        $unresolved = [];
        $contexts = is_array($bindingHealth['contexts'] ?? null) ? $bindingHealth['contexts'] : [];
        foreach ( array_slice($contexts, 0, 50) as $context ) {
            if ( ! is_array($context) ) {
                continue;
            }
            $contextKey = $this->sanitizer->token($context['context_key'] ?? null, 128);
            $bindingSet = $this->sanitizer->token($context['binding_set_id'] ?? null, 128);
            $bindingVersion = $this->sanitizer->version($context['binding_set_version'] ?? null);
            $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];

            foreach ( $facts as $fact ) {
                if ( count($unresolved) >= ProviderContract::MAX_UNRESOLVED || ! is_array($fact) ) {
                    break 2;
                }
                $slot = $this->sanitizer->token($fact['semantic_slot_key'] ?? null, 128);
                $status = $this->sanitizer->token($fact['status'] ?? null, 64);
                $bindingState = $this->sanitizer->token($fact['binding_state'] ?? null, 64);
                $reason = $this->sanitizer->token($fact['reason'] ?? null, 96);
                if ( null === $slot ) {
                    continue;
                }

                if ( (null !== $status && ! in_array($status, ['healthy', 'not_applicable'], true)) || in_array($bindingState, ['UNBOUND', 'NOT_PROVEN'], true) ) {
                    $unresolved[] = [
                        'kind' => 'binding',
                        'key' => $slot,
                        'state' => $bindingState ?? $status ?? 'unknown',
                        'status' => $status,
                        'reason_code' => $reason,
                        'context_key' => $contextKey,
                        'binding_set_id' => $bindingSet,
                        'binding_set_version' => $bindingVersion,
                    ];
                }

                $claims = is_array($fact['runtime_claims'] ?? null) ? $fact['runtime_claims'] : [];
                foreach ( $claims as $claim ) {
                    if ( count($unresolved) >= ProviderContract::MAX_UNRESOLVED || ! is_array($claim) ) {
                        break 2;
                    }
                    $claimName = $this->sanitizer->token($claim['claim'] ?? null, 96);
                    $evidenceState = $this->sanitizer->token($claim['evidence_state'] ?? null, 64);
                    if ( null === $claimName || null === $evidenceState || in_array($evidenceState, ['PROVEN', 'NOT_APPLICABLE'], true) ) {
                        continue;
                    }
                    $unresolved[] = [
                        'kind' => 'runtime_claim',
                        'key' => $slot . ':' . $claimName,
                        'state' => $evidenceState,
                        'status' => null,
                        'reason_code' => null,
                        'context_key' => $contextKey,
                        'binding_set_id' => $bindingSet,
                        'binding_set_version' => $bindingVersion,
                    ];
                }
            }
        }

        if ( is_array($topLevelUnknown) ) {
            foreach ( $topLevelUnknown as $unknown ) {
                if ( count($unresolved) >= ProviderContract::MAX_UNRESOLVED ) {
                    break;
                }
                $safe = $this->sanitizer->token($unknown, 96);
                if ( null !== $safe ) {
                    $unresolved[] = ['kind' => 'provider_unknown', 'key' => $safe, 'state' => 'UNKNOWN', 'status' => null, 'reason_code' => null];
                }
            }
        }

        $seen = [];
        $result = [];
        foreach ( $unresolved as $item ) {
            $fingerprint = hash('sha256', (string) wp_json_encode($item));
            if ( ! isset($seen[$fingerprint]) ) {
                $seen[$fingerprint] = true;
                $result[] = $item;
            }
        }
        return $result;
    }
}
