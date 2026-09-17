<?php
declare(strict_types=1);

namespace WDDTF\Providers;

use Throwable;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class ProviderRegistry {
    private ?array $discovery = null;

    public function discover(): array {
        if ( null !== $this->discovery ) {
            return $this->discovery;
        }

        $registrations = [];
        if ( function_exists('apply_filters') ) {
            try {
                $candidate = apply_filters(ProviderContract::REGISTRATION_FILTER, []);
            } catch (Throwable) {
                return $this->discovery = [
                    'providers' => [],
                    'errors' => [$this->globalError('registration_filter_failed')],
                ];
            }
            if ( ! is_array($candidate) ) {
                return $this->discovery = [
                    'providers' => [],
                    'errors' => [$this->globalError('registration_filter_invalid')],
                ];
            }
            $registrations = $candidate;
        }

        $providers = [];
        $errors = [];
        $conflicted = [];
        foreach ( $registrations as $registration ) {
            $validated = $this->validate($registration);
            if ( isset($validated['error']) ) {
                $errors[] = $validated['error'];
                continue;
            }
            $provider = $validated['provider'];
            $key = $provider['provider_key'];
            if ( isset($conflicted[$key]) ) {
                $errors[] = $this->providerError($key, 'duplicate_provider_key');
                continue;
            }
            if ( isset($providers[$key]) ) {
                unset($providers[$key]);
                $conflicted[$key] = true;
                $errors[] = $this->providerError($key, 'duplicate_provider_key');
                continue;
            }
            $providers[$key] = $provider;
        }
        ksort($providers, SORT_STRING);

        return $this->discovery = ['providers' => $providers, 'errors' => $errors];
    }

    /**
     * Resolves one requested provider from the single cached discovery result.
     *
     * Global discovery errors block every provider. Provider-scoped errors block only
     * the exact matching key. Rejected/unowned registrations remain diagnostic evidence
     * but never act as wildcard errors for an unrelated provider.
     */
    public function resolve(string $providerKey): array {
        $providerKey = sanitize_key($providerKey);
        $discovery = $this->discover();
        $blockingError = null;

        foreach ( $discovery['errors'] as $error ) {
            if ( ! is_array($error) ) {
                continue;
            }
            $scope = $error['scope'] ?? null;
            if ( 'global' === $scope ) {
                $blockingError = $error;
                break;
            }
            if ( 'provider' === $scope && ($error['provider_key'] ?? null) === $providerKey ) {
                $blockingError = $error;
                break;
            }
        }

        return [
            'provider_key' => $providerKey,
            'provider' => null === $blockingError ? ($discovery['providers'][$providerKey] ?? null) : null,
            'blocking_error' => $blockingError,
            'errors' => $discovery['errors'],
        ];
    }

    public function get(string $providerKey): ?array {
        return $this->resolve($providerKey)['provider'];
    }

    private function validate(mixed $registration): array {
        if ( ! is_array($registration) ) {
            return ['error' => $this->rejectedError('invalid_registration')];
        }

        $rawKey = $registration['provider_key'] ?? null;
        $key = is_string($rawKey) ? sanitize_key($rawKey) : '';
        if ( '' === $key || $key !== $rawKey ) {
            return ['error' => $this->rejectedError('invalid_provider_key')];
        }
        if ( ProviderContract::CONTRACT_VERSION !== ($registration['contract_version'] ?? null) ) {
            return ['error' => $this->providerError($key, 'incompatible_contract_version')];
        }

        $name = $this->safeLabel($registration['name'] ?? null, 120);
        $providerVersion = $this->safeToken($registration['provider_version'] ?? null, 64);
        $schemaVersion = $this->safeToken($registration['schema_version'] ?? null, 64);
        $callback = $registration['snapshot_callback'] ?? null;
        if ( null === $name || null === $providerVersion || null === $schemaVersion || ! is_callable($callback) ) {
            return ['error' => $this->providerError($key, 'invalid_registration')];
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

    private function globalError(string $reason): array {
        return ['scope' => 'global', 'provider_key' => null, 'reason' => $reason];
    }

    private function providerError(string $providerKey, string $reason): array {
        return ['scope' => 'provider', 'provider_key' => $providerKey, 'reason' => $reason];
    }

    private function rejectedError(string $reason): array {
        return ['scope' => 'rejected', 'provider_key' => null, 'reason' => $reason];
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
