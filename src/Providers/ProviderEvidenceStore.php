<?php
declare(strict_types=1);

namespace WDDTF\Providers;

use Closure;
use WDDTF\Privacy\Redactor;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class ProviderEvidenceStore {
    private const STATE_SCHEMA_VERSION = '1.0.0';
    private Closure $clock;

    public function __construct(?callable $clock = null) {
        $this->clock = Closure::fromCallable($clock ?? static fn(): int => time());
    }

    public function save(array $snapshot): array {
        $rawProviderKey = $snapshot['provider']['key'] ?? null;
        $providerKey = is_string($rawProviderKey) ? sanitize_key($rawProviderKey) : '';
        if (
            '' === $providerKey ||
            $providerKey !== $rawProviderKey ||
            ProviderContract::NORMALIZED_SCHEMA_VERSION !== ($snapshot['model_version'] ?? null)
        ) {
            throw new \InvalidArgumentException('Invalid normalized provider snapshot.');
        }

        $snapshot = ( new Redactor() )->redact($snapshot);
        if ( ! $this->isValidSnapshot($snapshot, $providerKey) ) {
            throw new \InvalidArgumentException('Invalid normalized provider snapshot.');
        }

        $fingerprint = $this->fingerprint($snapshot);
        $state = $this->loadState();
        if (
            ! array_key_exists($providerKey, $state['providers']) &&
            count($state['providers']) >= ProviderContract::MAX_PROVIDERS
        ) {
            throw new \RuntimeException('Provider evidence provider limit reached.');
        }

        $history = $state['providers'][$providerKey] ?? [];
        foreach ( $history as $entry ) {
            if ( is_array($entry) && hash_equals((string) ($entry['fingerprint'] ?? ''), $fingerprint) ) {
                return ['stored' => false, 'duplicate' => true, 'fingerprint' => $fingerprint, 'history_count' => count($history)];
            }
        }

        $history[] = [
            'fingerprint' => $fingerprint,
            'ingested_at_utc' => gmdate('c', ($this->clock)()),
            'snapshot' => $snapshot,
        ];
        if ( count($history) > ProviderContract::MAX_HISTORY_PER_PROVIDER ) {
            $history = array_slice($history, -1 * ProviderContract::MAX_HISTORY_PER_PROVIDER);
        }
        $state['providers'][$providerKey] = $history;
        $this->persist($state);

        return ['stored' => true, 'duplicate' => false, 'fingerprint' => $fingerprint, 'history_count' => count($history)];
    }

    public function history(string $providerKey): array {
        $providerKey = sanitize_key($providerKey);
        if ( '' === $providerKey ) {
            return [];
        }
        $state = $this->loadState();
        $history = $state['providers'][$providerKey] ?? [];
        return is_array($history) ? $history : [];
    }

    public function current(string $providerKey): ?array {
        $history = $this->history($providerKey);
        return empty($history) ? null : $history[count($history) - 1];
    }

    public function previous(string $providerKey): ?array {
        $history = $this->history($providerKey);
        return count($history) < 2 ? null : $history[count($history) - 2];
    }

    public function comparison(string $providerKey): array {
        $current = $this->current($providerKey);
        $previous = $this->previous($providerKey);
        if ( null === $current || null === $previous ) {
            return ['available' => false, 'reason' => 'previous_snapshot_unavailable', 'changes' => []];
        }
        $currentSnapshot = $current['snapshot'];
        $previousSnapshot = $previous['snapshot'];
        if (
            ($currentSnapshot['provider']['key'] ?? null) !== ($previousSnapshot['provider']['key'] ?? null) ||
            ($currentSnapshot['source']['schema_version'] ?? null) !== ($previousSnapshot['source']['schema_version'] ?? null)
        ) {
            return ['available' => false, 'reason' => 'incompatible_snapshot_schema', 'changes' => []];
        }

        $changes = [];
        $previousStatus = $previousSnapshot['current']['status'] ?? null;
        $currentStatus = $currentSnapshot['current']['status'] ?? null;
        if ( $previousStatus !== $currentStatus ) {
            $changes[] = ['kind' => 'current_status_changed', 'before' => $previousStatus, 'after' => $currentStatus];
        }
        $this->appendSetChanges('component', $previousSnapshot['current']['components'] ?? [], $currentSnapshot['current']['components'] ?? [], $changes);
        $this->appendSetChanges('unresolved_fact', $previousSnapshot['unresolved'] ?? [], $currentSnapshot['unresolved'] ?? [], $changes);

        $previousIncidents = $previousSnapshot['incidents'] ?? [];
        $currentIncidents = $currentSnapshot['incidents'] ?? [];
        $previousIncidentSet = array_fill_keys(array_map([$this, 'fingerprint'], $previousIncidents), true);
        $newIncidents = 0;
        foreach ( $currentIncidents as $incident ) {
            if ( ! isset($previousIncidentSet[$this->fingerprint($incident)]) ) {
                ++$newIncidents;
            }
        }
        if ( $newIncidents > 0 ) {
            $changes[] = ['kind' => 'new_incidents_observed', 'count' => $newIncidents];
        }

        return [
            'available' => true,
            'reason' => empty($changes) ? 'no_meaningful_change' : 'meaningful_change_observed',
            'previous_observed_at_utc' => $previousSnapshot['source']['observed_at_utc'] ?? null,
            'current_observed_at_utc' => $currentSnapshot['source']['observed_at_utc'] ?? null,
            'changes' => array_slice($changes, 0, 20),
        ];
    }

    public function reset(): void {
        delete_option(ProviderContract::STORE_OPTION);
    }

    private function appendSetChanges(string $kind, mixed $before, mixed $after, array &$changes): void {
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];
        $beforeSet = array_fill_keys(array_map([$this, 'fingerprint'], $before), true);
        $afterSet = array_fill_keys(array_map([$this, 'fingerprint'], $after), true);
        $added = count(array_diff_key($afterSet, $beforeSet));
        $removed = count(array_diff_key($beforeSet, $afterSet));
        if ( $added > 0 || $removed > 0 ) {
            $changes[] = ['kind' => $kind . '_set_changed', 'added' => $added, 'removed' => $removed];
        }
    }

    private function loadState(): array {
        $state = get_option(ProviderContract::STORE_OPTION, null);
        if ( null === $state || false === $state ) {
            return $this->initialState();
        }
        if (
            ! is_array($state) ||
            self::STATE_SCHEMA_VERSION !== ($state['schema_version'] ?? null) ||
            ! is_array($state['providers'] ?? null) ||
            count($state['providers']) > ProviderContract::MAX_PROVIDERS
        ) {
            throw new \RuntimeException('Provider evidence state is corrupt.');
        }
        foreach ( $state['providers'] as $providerKey => $history ) {
            if (
                ! is_string($providerKey) ||
                '' === $providerKey ||
                sanitize_key($providerKey) !== $providerKey ||
                ! is_array($history) ||
                count($history) > ProviderContract::MAX_HISTORY_PER_PROVIDER
            ) {
                throw new \RuntimeException('Provider evidence state is corrupt.');
            }
            foreach ( $history as $entry ) {
                if ( ! $this->isValidEntry($entry, $providerKey) ) {
                    throw new \RuntimeException('Provider evidence state is corrupt.');
                }
            }
        }
        return $state;
    }

    private function isValidEntry(mixed $entry, string $providerKey): bool {
        if (
            ! is_array($entry) ||
            ! is_string($entry['fingerprint'] ?? null) ||
            1 !== preg_match('/^[a-f0-9]{64}$/', $entry['fingerprint']) ||
            ! is_string($entry['ingested_at_utc'] ?? null) ||
            ! is_array($entry['snapshot'] ?? null) ||
            ! $this->isValidSnapshot($entry['snapshot'], $providerKey)
        ) {
            return false;
        }
        $ingested = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $entry['ingested_at_utc']);
        if ( false === $ingested ) {
            return false;
        }
        return hash_equals($entry['fingerprint'], $this->fingerprint($entry['snapshot']));
    }

    private function isValidSnapshot(array $snapshot, string $providerKey): bool {
        if (
            ProviderContract::NORMALIZED_SCHEMA_VERSION !== ($snapshot['model_version'] ?? null) ||
            $providerKey !== ($snapshot['provider']['key'] ?? null) ||
            ! is_array($snapshot['provider'] ?? null) ||
            ! is_array($snapshot['source'] ?? null) ||
            ! is_array($snapshot['environment'] ?? null) ||
            ! is_array($snapshot['current'] ?? null) ||
            ! is_array($snapshot['current']['components'] ?? null) ||
            ! is_array($snapshot['unresolved'] ?? null) ||
            ! is_array($snapshot['incidents'] ?? null) ||
            ! is_array($snapshot['recent_success'] ?? null) ||
            ! is_array($snapshot['privacy_boundary'] ?? null)
        ) {
            return false;
        }
        if (
            count($snapshot['current']['components']) > ProviderContract::MAX_COMPONENTS ||
            count($snapshot['unresolved']) > ProviderContract::MAX_UNRESOLVED ||
            count($snapshot['incidents']) > ProviderContract::MAX_INCIDENTS ||
            count($snapshot['recent_success']) > ProviderContract::MAX_RECENT_SUCCESS
        ) {
            return false;
        }
        $sourceTimestamp = $snapshot['source']['observed_at_utc'] ?? null;
        if ( ! is_string($sourceTimestamp) || false === \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $sourceTimestamp) ) {
            return false;
        }
        foreach ( $snapshot['incidents'] as $incident ) {
            if ( ! is_array($incident) || ! is_array($incident['events'] ?? null) || count($incident['events']) > ProviderContract::MAX_INCIDENT_EVENTS ) {
                return false;
            }
        }
        return true;
    }

    private function persist(array $state): void {
        if ( ! update_option(ProviderContract::STORE_OPTION, $state, false) ) {
            $existing = get_option(ProviderContract::STORE_OPTION, null);
            if ( $existing !== $state ) {
                throw new \RuntimeException('Provider evidence persistence failed.');
            }
        }
    }

    private function initialState(): array {
        return ['schema_version' => self::STATE_SCHEMA_VERSION, 'providers' => []];
    }

    private function fingerprint(mixed $value): string {
        $json = wp_json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', is_string($json) ? $json : '');
    }

    private function canonicalize(mixed $value): mixed {
        if ( ! is_array($value) ) {
            return $value;
        }
        if ( array_is_list($value) ) {
            return array_map([$this, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ( $value as $key => $child ) {
            $value[$key] = $this->canonicalize($child);
        }
        return $value;
    }
}
