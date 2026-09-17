# Diagnostic Provider Contract v1

Deep Diagnostics owns ingestion, bounded retention, safe interpretation, correlation boundaries, and administrator-facing explanation. A provider plugin remains the authority for its own domain state and must expose only privacy-safe diagnostic evidence.

## Direct registration

Direct providers register through the WordPress filter `wddtf_diagnostic_providers_v1`. Registration is lazy: DEEP invokes the callback only after an administrator explicitly requests a refresh. Registration does not authorize DEEP to read the provider's options or database state itself.

```php
add_filter(
    'wddtf_diagnostic_providers_v1',
    static function (array $providers): array {
        $providers[] = [
            'contract_version' => '1.0.0',
            'provider_key' => 'example_provider',
            'name' => 'Example Provider',
            'provider_version' => '2.4.0',
            'schema_version' => '1.0.0',
            'capabilities' => ['health_snapshot'],
            'snapshot_callback' => static function (): array {
                return [
                    'schema_version' => '1.0.0',
                    'generated_at_utc' => gmdate('c'),
                    'environment' => ['provider_api' => '2.0.0'],
                    'current_status' => 'ready',
                    'components' => [['key' => 'delivery.sms', 'status' => 'ready']],
                    'unresolved' => [],
                    'incidents' => [],
                    'recent_success' => [],
                    'privacy_boundary' => ['message_bodies' => 'OMITTED'],
                    'correlation_ref' => null,
                    'claim_ceiling' => 'provider_declared_evidence_only',
                ];
            },
        ];
        return $providers;
    }
);
```

`provider_key` is a stable sanitized key. `contract_version` versions the DEEP registration contract; `schema_version` versions the provider's snapshot shape. DEEP rejects incompatible registrations or direct snapshots that do not match the registered schema version. Duplicate registrations for the same provider key fail closed instead of selecting an arbitrary winner. Provider discovery is stable for one DEEP request.

## Snapshot fields admitted by DEEP

The generic direct snapshot admits only bounded metadata:

- `generated_at_utc`: **required** source timestamp in ISO-8601 form. DEEP stores its own ingestion timestamp separately and rejects evidence whose source chronology cannot be represented truthfully.
- `environment`: at most 20 sanitized key/version pairs.
- `current_status`: stable non-PII token.
- `components`: at most 50 key/status pairs.
- `unresolved`: at most 100 provider-declared key/state facts with optional status/reason code.
- `incidents`: at most the latest 25 historical incident traces, with at most 40 ordered events each.
- `recent_success`: at most 12 provider-declared successful traces.
- `privacy_boundary`: bounded declarations such as `OMITTED`.
- `correlation_ref`: optional exact opaque reference. DEEP never substitutes time proximity for this reference.
- `claim_ceiling`: optional stable token describing the provider-declared ceiling.

Incident records use provider-owned `stage`, `result`, `reason_code`, and `fallback` tokens. DEEP preserves admitted event order, selects the first supplied `FAIL`/`SKIP` boundary, and does not infer missing stages or duplicate provider business logic.

## Same-execution correlation for cooperating Providers

For a supported ordinary DEEP-observed PHP execution, Deep Diagnostics establishes one immutable opaque execution correlation reference. The current implementation uses the form `dx1_` followed by 16 URL-safe random characters (96 random bits; 20 characters total). Providers **must treat the value as opaque** and must not parse or derive meaning from its current format.

A cooperating Provider may read the current value during that same PHP execution through the read-only public accessor:

```php
$correlationRef = class_exists(\WDDTF\Providers\ProviderRuntimeContext::class)
    ? \WDDTF\Providers\ProviderRuntimeContext::currentExecutionCorrelationRef()
    : null;
```

The accessor does not invoke Provider callbacks, inspect Provider options/database state, or mutate Provider state. It returns `null` when DEEP has no supported ordinary execution context, including request classes whose normal DEEP report is intentionally not finalized. A Provider must not invent a replacement value when the accessor returns `null`.

When a Provider is already recording privacy-safe runtime or incident evidence in the same PHP execution, it may retain the exact opaque value unchanged and later return that same value as its snapshot or incident `correlation_ref`. Providers must never turn this value into a user or business identifier and must never place usernames, Entry/Form IDs, URLs, IP addresses, cookies, nonces, timestamps, database IDs, submitted values, credentials, secrets, or other PII into `correlation_ref`.

An exact match proves only that the Provider evidence explicitly carried the same retained reference as a concrete DEEP evidence item. DEEP may also report the matched evidence class (for example `deep_execution`, `cron_qualification_session`, `gravity_diagnostic_session`, or `gravity_trace`). The match does **not** prove that omitted Provider stages occurred, that a browser-visible outcome happened, that historical evidence is current, or that the Provider's business interpretation is correct. Provider evidence remains Provider evidence; DEEP evidence remains DEEP evidence.

If no exact reference is available, Provider and DEEP evidence may still be useful side-by-side context, but same-request/same-execution causality is **not proven**. DEEP never substitutes timestamp proximity. Snapshot-only Providers are not required to implement runtime correlation, and the GPP Support Bundle fallback remains valid without it.

## Privacy and ownership requirements

The callback must be read-only and privacy-safe before DEEP receives its result. Do not expose submitted form values, names, national IDs, phone numbers, emails, uploaded file names or contents, cookies, tokens, credentials, sensitive headers, raw request/response bodies, absolute server paths, raw host/form identifiers, or exception argument values.

DEEP applies an allowlist normalizer and its central Redactor before bounded persistence. Unknown fields are discarded. Storage is capped at 12 retained provider keys and five distinct normalized snapshots per provider; reaching the provider-key ceiling fails closed rather than silently evicting existing evidence. Exact duplicate snapshots do not create additional history entries. Corrupt or unreadable DEEP provider storage fails closed and is reported as a DEEP evidence-storage problem, not as provider failure.

A direct provider is an evidence seam, not a management seam. The callback must not ask DEEP to activate provider features, repair state, change configuration, or take business-domain actions. Generic DEEP interpretation stays provider-neutral; provider-specific guidance belongs at the adapter/presentation boundary.

## GPP support-bundle fallback

The first concrete adapter accepts only:

- `bundle_type = gpp.support_bundle`
- `schema_version = 1.0.0`

The adapter reads the privacy-safe shape published by GPP Support Bundle v1, including nested binding facts and runtime claims. Top-level `unknown_or_unproven` is not treated as a complete health summary: nested `UNBOUND` bindings and `NOT_PROVEN` runtime claims remain visible even when that top-level list is empty. Malformed or unknown child items are omitted safely without suppressing later valid facts from the same supported bundle section.

GPP bundle `generated_at_utc` is required and remains distinct from DEEP ingestion time. GPP's raw `form_id` is not persisted into DEEP's normalized Provider evidence. Current profile/binding state, historical incidents, and recent provider successes remain separate evidence categories.
