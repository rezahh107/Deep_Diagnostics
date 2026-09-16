<?php
declare(strict_types=1);

namespace WDDTF\Providers;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class ProviderRegistry {
    public function discover(): array {
        $registrations = [];
        if ( function_exists('apply_filters') ) {
            $candidate = apply_filters(ProviderContract::REGISTRATION_FILTER, []);
            if ( is_array($candidate) ) {
                $registrations = $candidate;
            }
        }

        $providers = [];
        $errors = [];
        foreach ( $registrations as $registration ) {
            $validated = $this->validate($registration);
            if ( isset($validated['error']) ) {
                $errors[] = $validated['error'];
                continue;
            }
            $provider = $validated['provider'];
            $key = $provider['provider_key'];
            if ( isset($providers[$key]) ) {
                $errors[] = ['provider_key' => $key, 'reason' => 'duplicate_provider_key'];
                continue;
            }
            $providers[$key] = $provider;
        }
        ksort($providers, SORT_STRING);

        return ['providers' => $providers, 'errors' => $errors];
    }

    public function get(string $providerKey): ?array {
        $providerKey = sanitize_key($providerKey);
        $discovery = $this->discover();
        return $discovery['providers'][$providerKey] ?? null;
    }

    private function validate(mixed $registration): array {
        if ( ! is_array($registration) ) {
            return ['error' => ['provider_key' => '', 'reason' => 'invalid_registration']];
        }

        $rawKey = $registration['provider_key'] ?? null;
        $key = is_string($rawKey) ? sanitize_key($rawKey) : '';
        if ( '' === $key || $key !== $rawKey ) {
            return ['error' => ['provider_key' => $key, 'reason' => 'invalid_provider_key']];
        }
        if ( ProviderContract::CONTRACT_VERSION !== ($registration['contract_version'] ?? null) ) {
            return ['error' => ['provider_key' => $key, 'reason' => 'incompatible_contract_version']];
        }

        $name = $this->safeLabel($registration['name'] ?? null, 120);
        $providerVersion = $this->safeToken($registration['provider_version'] ?? null, 64);
        $schemaVersion = $this->safeToken($registration['schema_version'] ?? null, 64);
        $callback = $registration['snapshot_callback'] ?? null;
        if ( null === $name || null === $providerVersion || null === $schemaVersion || ! is_callable($callback) ) {
            return ['error' => ['provider_key' => $key, 'reason' => 'invalid_registration']];
        }

        $capabilities = [];
        if ( isset($registration['capabilities']) && is_array($registration['capabilities']) ) {
            foreach ( array_slice($registration['capabilities'], 0, 12) as $capability ) {
                if ( ! is_string($capability) ) {
                    continue;
                }
                $safe = sanitize_key($capability);
                if ( '' !== $safe && $safe === $capability ) {
                    $capabilities[] = $safe;
                }
            }
        }

        return ['provider' => [
            'contract_version' => ProviderContract::CONTRACT_VERSION,
            'provider_key' => $key,
            'name' => $name,
            'provider_version' => $providerVersion,
            'schema_version' => $schemaVersion,
            'capabilities' => array_values(array_unique($capabilities)),
            'snapshot_callback' => $callback,
        ]];
    }

    private function safeLabel(mixed $value, int $maxLength): ?string {
        if ( ! is_string($value) ) {
            return null;
        }
        $value = trim($value);
        if ( '' === $value || strlen($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) ) {
            return null;
        }
        return $value;
    }

    private function safeToken(mixed $value, int $maxLength): ?string {
        if ( ! is_string($value) || '' === $value || strlen($value) > $maxLength ) {
            return null;
        }
        return 1 === preg_match('/^[A-Za-z0-9._+-]+$/', $value) ? $value : null;
    }
}
