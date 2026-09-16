<?php
declare(strict_types=1);

namespace WDDTF\Providers;

use JsonException;
use Throwable;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class ProviderEvidenceService {
    public function __construct(
        private ?ProviderRegistry $registry = null,
        private ?ProviderEvidenceStore $store = null,
        private ?GppSupportBundleAdapter $gppAdapter = null,
        private ?DirectSnapshotAdapter $directAdapter = null
    ) {
        $this->registry ??= new ProviderRegistry();
        $this->store ??= new ProviderEvidenceStore();
        $this->gppAdapter ??= new GppSupportBundleAdapter();
        $this->directAdapter ??= new DirectSnapshotAdapter();
    }

    public function importGppBundle(string $raw): array {
        if ( '' === $raw ) {
            return $this->failure('empty_import', __('The selected Support Bundle is empty.', 'wp-deep-diagnostics'));
        }
        if ( strlen($raw) > ProviderContract::MAX_IMPORT_BYTES ) {
            return $this->failure(
                'import_too_large',
                sprintf(
                    __('The Support Bundle is larger than the %d KiB import limit.', 'wp-deep-diagnostics'),
                    intdiv(ProviderContract::MAX_IMPORT_BYTES, 1024)
                )
            );
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->failure('invalid_json', __('The selected file is not valid JSON.', 'wp-deep-diagnostics'));
        }
        if ( ! is_array($decoded) ) {
            return $this->failure('invalid_json', __('The selected JSON does not contain a diagnostic bundle object.', 'wp-deep-diagnostics'));
        }

        try {
            $stored = $this->store->save($this->gppAdapter->normalize($decoded));
        } catch (ProviderImportException $exception) {
            return $this->failure($exception->reason(), $exception->getMessage());
        } catch (Throwable) {
            return $this->failure('provider_persistence_failed', __('Deep Diagnostics could not safely store the normalized provider evidence.', 'wp-deep-diagnostics'));
        }

        return [
            'ok' => true,
            'reason' => ! empty($stored['duplicate']) ? 'bundle_already_loaded' : 'bundle_loaded',
            'message' => ! empty($stored['duplicate'])
                ? __('This compatible Support Bundle was already loaded; no duplicate history entry was created.', 'wp-deep-diagnostics')
                : __('Compatible GPP Support Bundle loaded.', 'wp-deep-diagnostics'),
            'stored' => $stored,
        ];
    }

    public function captureDirect(string $providerKey): array {
        $providerKey = sanitize_key($providerKey);
        $registration = $this->registry->get($providerKey);
        if ( null === $registration ) {
            return $this->failure('direct_provider_unavailable', __('No compatible direct diagnostic provider is registered for this provider key.', 'wp-deep-diagnostics'));
        }

        try {
            $payload = ($registration['snapshot_callback'])();
            $stored = $this->store->save($this->directAdapter->normalize($registration, $payload));
        } catch (ProviderImportException $exception) {
            return $this->failure($exception->reason(), $exception->getMessage());
        } catch (Throwable) {
            return $this->failure('direct_provider_error', __('The direct diagnostic provider could not return compatible privacy-safe evidence.', 'wp-deep-diagnostics'));
        }

        return [
            'ok' => true,
            'reason' => ! empty($stored['duplicate']) ? 'direct_snapshot_unchanged' : 'direct_snapshot_loaded',
            'message' => ! empty($stored['duplicate'])
                ? __('The direct provider snapshot is unchanged; no duplicate history entry was created.', 'wp-deep-diagnostics')
                : __('A new privacy-safe direct provider snapshot was loaded.', 'wp-deep-diagnostics'),
            'stored' => $stored,
        ];
    }

    public function diagnostics(string $providerKey, array $deepReport = []): array {
        $providerKey = sanitize_key($providerKey);
        $discovery = $this->registry->discover();
        $registration = $discovery['providers'][$providerKey] ?? null;
        $registryError = null;
        foreach ( $discovery['errors'] as $error ) {
            if ( ($error['provider_key'] ?? null) === $providerKey || '' === ($error['provider_key'] ?? null) ) {
                $registryError = $error['reason'] ?? 'provider_registration_error';
                break;
            }
        }

        try {
            $currentEntry = $this->store->current($providerKey);
            $previousEntry = $this->store->previous($providerKey);
            $history = $this->store->history($providerKey);
            $comparison = $this->store->comparison($providerKey);
        } catch (Throwable) {
            return $this->storageFailureDiagnostics($providerKey, $registration, $registryError);
        }

        $snapshot = is_array($currentEntry['snapshot'] ?? null) ? $currentEntry['snapshot'] : null;
        $incidents = [];
        if ( is_array($snapshot['incidents'] ?? null) ) {
            foreach ( $snapshot['incidents'] as $incident ) {
                if ( is_array($incident) ) {
                    $incidents[] = $this->interpretIncident($incident, $deepReport);
                }
            }
        }

        return [
            'provider_key' => $providerKey,
            'connection' => $this->connectionState($providerKey, $snapshot, $registration, $registryError),
            'direct_available' => null !== $registration,
            'current_entry' => $currentEntry,
            'previous_entry' => $previousEntry,
            'history_count' => count($history),
            'retention_limit' => ProviderContract::MAX_HISTORY_PER_PROVIDER,
            'comparison' => $comparison,
            'interpretation' => $this->interpret($providerKey, $snapshot),
            'incidents' => $incidents,
            'recent_success' => is_array($snapshot['recent_success'] ?? null) ? $snapshot['recent_success'] : [],
            'correlation' => $this->correlate($snapshot, $deepReport),
            'technical_evidence' => $snapshot,
            'import_limit_bytes' => ProviderContract::MAX_IMPORT_BYTES,
        ];
    }

    public function exportJson(string $providerKey, array $deepReport = []): ?string {
        $diagnostics = $this->diagnostics($providerKey, $deepReport);
        if ( ! is_array($diagnostics['technical_evidence'] ?? null) ) {
            return null;
        }
        $export = [
            'export_schema_version' => '1.0.0',
            'exported_by' => [
                'plugin' => 'WP Deep Diagnostics',
                'version' => defined('WDDTF_VERSION') ? WDDTF_VERSION : 'unknown',
            ],
            'provider_evidence' => $diagnostics['technical_evidence'],
            'comparison' => $diagnostics['comparison'],
            'correlation' => $diagnostics['correlation'],
        ];
        $json = wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json . "\n" : null;
    }

    private function connectionState(string $providerKey, ?array $snapshot, ?array $registration, ?string $registryError): array {
        if ( null !== $registryError ) {
            return [
                'status' => 'incompatible_provider_schema',
                'label' => __('Incompatible direct provider registration', 'wp-deep-diagnostics'),
                'meaning' => __('A plugin attempted to register diagnostic evidence, but its provider contract is not compatible or unambiguous for this Deep Diagnostics build.', 'wp-deep-diagnostics'),
            ];
        }
        if ( is_array($snapshot) && 'direct' === ($snapshot['source']['mode'] ?? null) ) {
            return [
                'status' => 'direct_provider_connected',
                'label' => __('Direct provider connected', 'wp-deep-diagnostics'),
                'meaning' => __('The current evidence came directly from a compatible registered provider callback.', 'wp-deep-diagnostics'),
            ];
        }
        if ( is_array($snapshot) ) {
            return [
                'status' => 'support_bundle_loaded',
                'label' => __('Compatible support bundle loaded', 'wp-deep-diagnostics'),
                'meaning' => null !== $registration
                    ? __('The current evidence came from an imported bundle. A compatible direct provider is also registered and can be refreshed explicitly.', 'wp-deep-diagnostics')
                    : __('The current evidence came from an imported privacy-safe support bundle.', 'wp-deep-diagnostics'),
            ];
        }
        if ( null !== $registration ) {
            return [
                'status' => 'direct_provider_available_no_snapshot',
                'label' => __('Direct provider available — no snapshot loaded yet', 'wp-deep-diagnostics'),
                'meaning' => __('A compatible provider is registered, but Deep Diagnostics has not collected a snapshot. Use the explicit refresh action below.', 'wp-deep-diagnostics'),
            ];
        }
        if ( 'gpp' === $providerKey && $this->gppDetected() ) {
            return [
                'status' => 'provider_detected_no_data',
                'label' => __('GPP detected but no provider data is available', 'wp-deep-diagnostics'),
                'meaning' => __('GPP appears to be loaded, but it has not registered a compatible direct provider and no compatible Support Bundle has been imported.', 'wp-deep-diagnostics'),
            ];
        }
        return [
            'status' => 'provider_not_detected',
            'label' => 'gpp' === $providerKey ? __('GPP provider data not detected', 'wp-deep-diagnostics') : __('Provider data not detected', 'wp-deep-diagnostics'),
            'meaning' => __('No compatible direct provider or imported evidence is currently available.', 'wp-deep-diagnostics'),
        ];
    }

    private function interpret(string $providerKey, ?array $snapshot): array {
        if ( null === $snapshot ) {
            return [
                'result' => 'gpp' === $providerKey ? __('No GPP diagnostic evidence is loaded yet.', 'wp-deep-diagnostics') : __('No diagnostic provider evidence is loaded yet.', 'wp-deep-diagnostics'),
                'meaning' => __('Deep Diagnostics needs a compatible direct snapshot or support bundle before it can explain provider state.', 'wp-deep-diagnostics'),
                'proven' => [__('No provider snapshot has been admitted into Deep Diagnostics.', 'wp-deep-diagnostics')],
                'unresolved' => [__('Provider health and runtime behavior are not proven without provider evidence.', 'wp-deep-diagnostics')],
                'next_step' => 'gpp' === $providerKey
                    ? __('Generate a Support Bundle in GPP and import it here, or refresh a compatible direct provider if GPP registers one.', 'wp-deep-diagnostics')
                    : __('Load compatible provider evidence using one of the supported acquisition modes.', 'wp-deep-diagnostics'),
                'limitations' => [__('Deep Diagnostics does not read the provider plugin’s private options or configuration to fill this gap.', 'wp-deep-diagnostics')],
            ];
        }

        $unresolved = is_array($snapshot['unresolved'] ?? null) ? $snapshot['unresolved'] : [];
        $incidents = is_array($snapshot['incidents'] ?? null) ? $snapshot['incidents'] : [];
        $sourceTime = $snapshot['source']['observed_at_utc'] ?? null;
        $currentStatus = is_string($snapshot['current']['status'] ?? null) ? $snapshot['current']['status'] : null;

        if ( ! empty($unresolved) ) {
            $result = sprintf(
                _n('%d unresolved or unproven current fact is present in the provider snapshot.', '%d unresolved or unproven current facts are present in the provider snapshot.', count($unresolved), 'wp-deep-diagnostics'),
                count($unresolved)
            );
            $meaning = __('Deep Diagnostics is preserving provider-declared current facts that still need mapping, proof, or review. Historical incidents are evaluated separately and do not overwrite this current snapshot.', 'wp-deep-diagnostics');
        } elseif ( ! empty($incidents) ) {
            $result = sprintf(
                _n('No unresolved current fact was observed; %d historical provider incident is retained separately.', 'No unresolved current facts were observed; %d historical provider incidents are retained separately.', count($incidents), 'wp-deep-diagnostics'),
                count($incidents)
            );
            $meaning = __('The admitted current snapshot does not expose an unresolved fact in the fields DEEP understands, but historical provider incidents still exist. Those incidents are evidence about earlier observations, not proof that the same failure is current now.', 'wp-deep-diagnostics');
        } else {
            $result = __('No unresolved current facts were observed in the admitted provider snapshot.', 'wp-deep-diagnostics');
            $meaning = __('The provider snapshot did not expose a current unresolved or unproven fact in the fields Deep Diagnostics understands. This is not a blanket proof that every provider path is healthy.', 'wp-deep-diagnostics');
        }

        $proven = [];
        if ( is_string($sourceTime) && '' !== $sourceTime ) {
            $proven[] = sprintf(__('The admitted snapshot represents provider evidence generated at %s.', 'wp-deep-diagnostics'), $sourceTime);
        }
        if ( null !== $currentStatus ) {
            $proven[] = sprintf(__('The admitted snapshot current-status marker is %s.', 'wp-deep-diagnostics'), $currentStatus);
        }
        $componentCount = count($snapshot['current']['components'] ?? []);
        $proven[] = sprintf(
            _n('%d active/current component was admitted.', '%d active/current components were admitted.', $componentCount, 'wp-deep-diagnostics'),
            $componentCount
        );
        if ( ! empty($incidents) ) {
            $proven[] = sprintf(
                _n('%d historical provider incident is retained separately.', '%d historical provider incidents are retained separately.', count($incidents), 'wp-deep-diagnostics'),
                count($incidents)
            );
        }

        $nextStep = ! empty($unresolved)
            ? $this->nextStepForUnresolved($unresolved)
            : (! empty($incidents)
                ? __('Open the most relevant historical incident below to inspect its ordered provider chain. If the problem is still reproducible, collect a fresh bundle so current state can be compared without assuming the old incident still applies.', 'wp-deep-diagnostics')
                : __('If the real behavior is still wrong, reproduce it and collect a fresh provider snapshot or incident so the missing boundary can be observed.', 'wp-deep-diagnostics'));

        return [
            'result' => $result,
            'meaning' => $meaning,
            'proven' => $proven,
            'unresolved' => [
                __('An imported or direct snapshot is point-in-time evidence; it does not prove what changed after its source timestamp.', 'wp-deep-diagnostics'),
                __('A provider incident proves only the ordered evidence the provider supplied. Missing stages are not inferred.', 'wp-deep-diagnostics'),
                __('Without an explicit exact correlation reference, provider evidence is context beside DEEP evidence, not proof that both came from the same request or cause.', 'wp-deep-diagnostics'),
            ],
            'next_step' => $nextStep,
            'limitations' => [
                __('DEEP does not activate GPP profiles, repair bindings, change runtime claims, or write GPP configuration.', 'wp-deep-diagnostics'),
                __('DEEP does not independently reinterpret GPP domain reason codes as stronger conclusions than the supplied evidence supports.', 'wp-deep-diagnostics'),
            ],
        ];
    }

    private function nextStepForUnresolved(array $unresolved): string {
        foreach ( $unresolved as $fact ) {
            $kind = $fact['kind'] ?? null;
            $state = $fact['state'] ?? null;
            if ( 'binding' === $kind && 'UNBOUND' === $state ) {
                return __('At least one GPP binding is UNBOUND. Open GPP Mapping & Binding Health and explicitly map the semantic meaning there; DEEP will not repair it.', 'wp-deep-diagnostics');
            }
            if ( 'runtime_claim' === $kind || 'NOT_PROVEN' === $state ) {
                return __('At least one GPP condition is NOT_PROVEN. Review the corresponding mapping/readiness evidence in GPP and reproduce the relevant surface after GPP has established that proof.', 'wp-deep-diagnostics');
            }
            if ( in_array($fact['status'] ?? null, ['stale_source_missing', 'ambiguous_needs_review'], true) ) {
                return __('GPP reports a mapping that needs review. Use GPP Mapping & Binding Health to resolve the authoritative mapping before collecting another snapshot.', 'wp-deep-diagnostics');
            }
        }
        return __('Review the first unresolved provider fact in GPP, resolve it in the owning plugin, then collect a new snapshot to confirm the state changed.', 'wp-deep-diagnostics');
    }

    private function interpretIncident(array $incident, array $deepReport): array {
        $first = is_array($incident['first_inconsistent_boundary'] ?? null) ? $incident['first_inconsistent_boundary'] : null;
        $stage = $first['stage'] ?? null;
        $result = $first['result'] ?? null;
        $fallback = $first['fallback'] ?? null;
        if ( null === $first ) {
            $meaning = __('The provider retained an incident record, but no FAIL or SKIP boundary is present in the admitted event sequence. DEEP does not invent a missing failing stage.', 'wp-deep-diagnostics');
            $next = __('Inspect the technical provider chronology and collect a newer provider snapshot if the relevant boundary was not recorded.', 'wp-deep-diagnostics');
        } elseif ( 'SKIP' === $result ) {
            $meaning = __('The provider recorded a degraded/skip decision at this boundary. A fallback may have preserved host behavior; this does not by itself mean the host plugin failed.', 'wp-deep-diagnostics');
            $next = $this->nextStepForStage((string) $stage);
        } else {
            $meaning = __('The provider recorded a failure at this boundary. DEEP preserves that provider decision and its reason code without reimplementing the provider’s domain rules.', 'wp-deep-diagnostics');
            $next = $this->nextStepForStage((string) $stage);
        }
        return $incident + [
            'plain_meaning' => $meaning,
            'proves' => null === $first
                ? __('Only that the provider supplied this bounded incident chronology.', 'wp-deep-diagnostics')
                : sprintf(__('The provider sequence reached the recorded boundary %s and first deviated from PASS there.', 'wp-deep-diagnostics'), (string) $stage),
            'does_not_prove' => __('This historical incident does not prove the provider is still in the same state now, does not prove omitted stages occurred, and does not prove a DEEP request caused it without exact correlation.', 'wp-deep-diagnostics'),
            'next_step' => $next,
            'fallback_note' => null !== $fallback
                ? __('The provider recorded a fallback at this boundary; DEEP treats it as provider evidence, not as proof of host failure.', 'wp-deep-diagnostics')
                : null,
            'correlation' => $this->correlateReference($incident['correlation_ref'] ?? null, $deepReport),
        ];
    }

    private function nextStepForStage(string $stage): string {
        if ( str_contains($stage, 'PROFILE') ) {
            return __('Inspect the corresponding profile/surface activation and readiness in GPP, then collect a fresh snapshot after the owning GPP state is corrected.', 'wp-deep-diagnostics');
        }
        if ( str_contains($stage, 'BINDING') ) {
            return __('Inspect the corresponding mapping in GPP Mapping & Binding Health. Repair it in GPP, not in DEEP, then collect a fresh snapshot.', 'wp-deep-diagnostics');
        }
        if ( str_contains($stage, 'HOST') ) {
            return __('Inspect the host context/authorization evidence together with GPP’s own incident details. DEEP cannot decide from this provider event alone which host condition was absent.', 'wp-deep-diagnostics');
        }
        return __('Inspect this stage in GPP using the provider reason code and fallback, correct the owning GPP state if needed, then collect a fresh snapshot.', 'wp-deep-diagnostics');
    }

    private function correlate(?array $snapshot, array $deepReport): array {
        if ( null === $snapshot ) {
            return [
                'exact' => false,
                'linked' => false,
                'reason' => 'provider_snapshot_unavailable',
                'meaning' => __('No provider snapshot is available for correlation.', 'wp-deep-diagnostics'),
            ];
        }
        return $this->correlateReference($snapshot['correlation_ref'] ?? null, $deepReport);
    }

    private function correlateReference(mixed $reference, array $deepReport): array {
        if ( ! is_string($reference) || '' === $reference ) {
            return [
                'exact' => false,
                'linked' => false,
                'reason' => 'exact_correlation_ref_absent',
                'meaning' => __('No exact compatible correlation reference was supplied. Provider and DEEP evidence may be viewed together only as context; causal linkage is not proven.', 'wp-deep-diagnostics'),
            ];
        }
        foreach ( $this->deepCorrelationReferences($deepReport) as $candidate ) {
            if ( hash_equals($candidate, $reference) ) {
                return [
                    'exact' => true,
                    'linked' => true,
                    'reason' => 'exact_reference_match',
                    'meaning' => __('The provider supplied an exact reference that matches retained DEEP evidence. Only that explicitly matched evidence is correlated.', 'wp-deep-diagnostics'),
                ];
            }
        }
        return [
            'exact' => true,
            'linked' => false,
            'reason' => 'exact_reference_not_found',
            'meaning' => __('The provider supplied an exact correlation reference, but no matching retained DEEP evidence is available. DEEP does not substitute timestamp proximity.', 'wp-deep-diagnostics'),
        ];
    }

    private function deepCorrelationReferences(array $report): array {
        $references = [];
        $cron = $report['layers']['cron']['qualification']['session_id'] ?? null;
        if ( is_string($cron) && '' !== $cron ) {
            $references[] = $cron;
        }
        $gravity = $report['layers']['gravity']['inbox_observation'] ?? [];
        $gravitySession = $gravity['session_id'] ?? null;
        if ( is_string($gravitySession) && '' !== $gravitySession ) {
            $references[] = $gravitySession;
        }
        if ( is_array($gravity['traces'] ?? null) ) {
            foreach ( $gravity['traces'] as $trace ) {
                $ref = is_array($trace) ? ($trace['trace_ref'] ?? null) : null;
                if ( is_string($ref) && '' !== $ref ) {
                    $references[] = $ref;
                }
            }
        }
        return array_values(array_unique($references));
    }

    private function storageFailureDiagnostics(string $providerKey, ?array $registration, ?string $registryError): array {
        return [
            'provider_key' => $providerKey,
            'connection' => [
                'status' => 'evidence_store_error',
                'label' => __('Provider evidence storage error', 'wp-deep-diagnostics'),
                'meaning' => __('Deep Diagnostics could not safely read its normalized provider evidence store, so it is not making a provider health claim from that state.', 'wp-deep-diagnostics'),
            ],
            'direct_available' => null !== $registration && null === $registryError,
            'current_entry' => null,
            'previous_entry' => null,
            'history_count' => 0,
            'retention_limit' => ProviderContract::MAX_HISTORY_PER_PROVIDER,
            'comparison' => ['available' => false, 'reason' => 'evidence_store_error', 'changes' => []],
            'interpretation' => [
                'result' => __('Stored provider evidence is unavailable.', 'wp-deep-diagnostics'),
                'meaning' => __('DEEP failed closed instead of interpreting corrupt or unreadable stored evidence.', 'wp-deep-diagnostics'),
                'proven' => [__('The DEEP provider evidence store could not be validated for this read.', 'wp-deep-diagnostics')],
                'unresolved' => [__('Provider health, current state, incidents, and comparison are not proven while the DEEP evidence store is unreadable.', 'wp-deep-diagnostics')],
                'next_step' => __('Inspect or restore the Deep Diagnostics provider-evidence storage before importing or comparing provider evidence again. Do not change GPP configuration based on this DEEP storage error.', 'wp-deep-diagnostics'),
                'limitations' => [__('This is a DEEP evidence-storage failure, not evidence that the provider plugin or host runtime failed.', 'wp-deep-diagnostics')],
            ],
            'incidents' => [],
            'recent_success' => [],
            'correlation' => [
                'exact' => false,
                'linked' => false,
                'reason' => 'evidence_store_error',
                'meaning' => __('Provider correlation is unavailable because normalized provider evidence could not be read safely.', 'wp-deep-diagnostics'),
            ],
            'technical_evidence' => null,
            'import_limit_bytes' => ProviderContract::MAX_IMPORT_BYTES,
        ];
    }

    private function gppDetected(): bool {
        return defined('GPP_PLUGIN_FILE') || class_exists('GravityPresentationProfiles\\GravityForms\\AddOn', false);
    }

    private function failure(string $reason, string $message): array {
        return ['ok' => false, 'reason' => $reason, 'message' => $message];
    }
}
