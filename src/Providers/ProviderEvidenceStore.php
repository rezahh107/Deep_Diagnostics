<?php
declare(strict_types=1);

namespace WDDTF\Providers;

use Closure;
use Throwable;
use WDDTF\Privacy\Redactor;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class ProviderEvidenceStore {
    private const STATE_SCHEMA_VERSION = '1.0.0';
    private const LOCK_TTL = 30;
    private const LOCK_ATTEMPTS = 8;
    private const LOCK_RETRY_US = 25000;

    private Closure $clock;
    private Closure $lockTokenFactory;
    private Closure $sleeper;
    private ?Closure $transactionProbe;

    public function __construct(
        ?callable $clock = null,
        ?callable $lockTokenFactory = null,
        ?callable $sleeper = null,
        ?callable $transactionProbe = null
    ) {
        $this->clock = Closure::fromCallable($clock ?? static fn(): int => time());
        $this->lockTokenFactory = Closure::fromCallable(
            $lockTokenFactory ?? static fn(): string => 'plk-' . bin2hex(random_bytes(12))
        );
        $this->sleeper = Closure::fromCallable(
            $sleeper ?? static function(int $microseconds): void {
                usleep($microseconds);
            }
        );
        $this->transactionProbe = null === $transactionProbe ? null : Closure::fromCallable($transactionProbe);
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

        $owner = $this->acquireLock();
        if ( null === $owner ) {
            throw new \RuntimeException('Provider evidence mutation lock unavailable.');
        }

        $result = null;
        $failure = null;

        try {
            $this->probe('after_lock_acquired', ['provider_key' => $providerKey, 'owner' => $owner]);

            // Shared-state decisions intentionally use state reloaded only after lock ownership.
            $observed = $this->loadStateSnapshot();
            $state = $observed['state'];
            $this->probe('after_state_reload', ['provider_key' => $providerKey, 'state' => $state]);

            if (
                ! array_key_exists($providerKey, $state['providers']) &&
                count($state['providers']) >= ProviderContract::MAX_PROVIDERS
            ) {
                throw new \RuntimeException('Provider evidence provider limit reached.');
            }

            $history = $state['providers'][$providerKey] ?? [];
            foreach ( $history as $entry ) {
                if ( is_array($entry) && hash_equals((string) ($entry['fingerprint'] ?? ''), $fingerprint) ) {
                    if ( ! $this->ownsActiveLock($owner) ) {
                        throw new \RuntimeException('Provider evidence mutation lock lost.');
                    }
                    $result = [
                        'stored' => false,
                        'duplicate' => true,
                        'fingerprint' => $fingerprint,
                        'history_count' => count($history),
                    ];
                    break;
                }
            }

            if ( null === $result ) {
                $history[] = [
                    'fingerprint' => $fingerprint,
                    'ingested_at_utc' => gmdate('c', ($this->clock)()),
                    'snapshot' => $snapshot,
                ];
                if ( count($history) > ProviderContract::MAX_HISTORY_PER_PROVIDER ) {
                    $history = array_slice($history, -1 * ProviderContract::MAX_HISTORY_PER_PROVIDER);
                }
                $state['providers'][$providerKey] = $history;

                $this->probe('before_commit', ['provider_key' => $providerKey, 'state' => $state]);
                if ( ! $this->ownsActiveLock($owner) ) {
                    throw new \RuntimeException('Provider evidence mutation lock lost.');
                }
                $this->probe('after_commit_ownership_check', [
                    'provider_key' => $providerKey,
                    'expected_exists' => $observed['exists'],
                    'expected_state' => $observed['stored'],
                    'next_state' => $state,
                ]);

                $this->persistIfUnchanged($state, $observed['exists'], $observed['stored']);

                // A write completed after lease loss is persistence uncertainty, never success.
                if ( ! $this->ownsActiveLock($owner) ) {
                    throw new \RuntimeException('Provider evidence mutation ownership became uncertain.');
                }

                $result = [
                    'stored' => true,
                    'duplicate' => false,
                    'fingerprint' => $fingerprint,
                    'history_count' => count($history),
                ];
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $released = $this->releaseLock($owner);
        if ( ! $released && null === $failure ) {
            $failure = new \RuntimeException('Provider evidence mutation lock release failed.');
        }

        if ( null !== $failure ) {
            throw $failure;
        }
        if ( ! is_array($result) ) {
            throw new \RuntimeException('Provider evidence mutation result is unavailable.');
        }

        return $result;
    }

    public function view(string $providerKey): array {
        $providerKey = sanitize_key($providerKey);
        if ( '' === $providerKey ) {
            return $this->emptyView();
        }

        // One logical provider projection is derived from exactly one validated Store state.
        $state = $this->loadState();
        $history = $state['providers'][$providerKey] ?? [];
        $history = is_array($history) ? $history : [];
        $historyCount = count($history);
        $current = 0 === $historyCount ? null : $history[$historyCount - 1];
        $previous = $historyCount < 2 ? null : $history[$historyCount - 2];

        return [
            'history' => $history,
            'history_count' => $historyCount,
            'current' => $current,
            'previous' => $previous,
            'comparison' => $this->comparisonFromHistory($history),
        ];
    }

    public function history(string $providerKey): array {
        return $this->view($providerKey)['history'];
    }

    public function current(string $providerKey): ?array {
        return $this->view($providerKey)['current'];
    }

    public function previous(string $providerKey): ?array {
        return $this->view($providerKey)['previous'];
    }

    public function comparison(string $providerKey): array {
        return $this->view($providerKey)['comparison'];
    }

    public function reset(): void {
        $owner = $this->acquireLock();
        if ( null === $owner ) {
            throw new \RuntimeException('Provider evidence mutation lock unavailable.');
        }

        $failure = null;
        try {
            $observed = $this->loadStateSnapshot();
            $this->probe('after_reset_state_reload', [
                'expected_exists' => $observed['exists'],
                'expected_state' => $observed['stored'],
            ]);

            if ( $observed['exists'] ) {
                if ( ! $this->ownsActiveLock($owner) ) {
                    throw new \RuntimeException('Provider evidence mutation lock lost.');
                }
                $this->probe('after_reset_ownership_check', [
                    'expected_state' => $observed['stored'],
                ]);
                $this->deleteStoreIfUnchanged($observed['stored']);

                if ( ! $this->ownsActiveLock($owner) ) {
                    throw new \RuntimeException('Provider evidence mutation ownership became uncertain.');
                }
            } elseif ( ! $this->ownsActiveLock($owner) ) {
                throw new \RuntimeException('Provider evidence mutation lock lost.');
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $released = $this->releaseLock($owner);
        if ( ! $released && null === $failure ) {
            $failure = new \RuntimeException('Provider evidence mutation lock release failed.');
        }
        if ( null !== $failure ) {
            throw $failure;
        }
    }

    private function comparisonFromHistory(array $history): array {
        $historyCount = count($history);
        if ( $historyCount < 2 ) {
            return ['available' => false, 'reason' => 'previous_snapshot_unavailable', 'changes' => []];
        }

        $previous = $history[$historyCount - 2];
        $current = $history[$historyCount - 1];
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

    private function emptyView(): array {
        return [
            'history' => [],
            'history_count' => 0,
            'current' => null,
            'previous' => null,
            'comparison' => ['available' => false, 'reason' => 'previous_snapshot_unavailable', 'changes' => []],
        ];
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
        return $this->loadStateSnapshot()['state'];
    }

    private function loadStateSnapshot(): array {
        $missing = new \stdClass();
        $stored = get_option(ProviderContract::STORE_OPTION, $missing);
        if ( $stored === $missing ) {
            return [
                'exists' => false,
                'stored' => null,
                'state' => $this->initialState(),
            ];
        }
        if (
            ! is_array($stored) ||
            self::STATE_SCHEMA_VERSION !== ($stored['schema_version'] ?? null) ||
            ! is_array($stored['providers'] ?? null) ||
            count($stored['providers']) > ProviderContract::MAX_PROVIDERS
        ) {
            throw new \RuntimeException('Provider evidence state is corrupt.');
        }
        foreach ( $stored['providers'] as $providerKey => $history ) {
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
        return [
            'exists' => true,
            'stored' => $stored,
            'state' => $stored,
        ];
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

    private function persistIfUnchanged(array $state, bool $expectedExists, ?array $expectedState): void {
        if ( ! $expectedExists ) {
            if ( ! add_option(ProviderContract::STORE_OPTION, $state, '', false) ) {
                throw new \RuntimeException('Provider evidence persistence conflict.');
            }
        } else {
            global $wpdb;

            if ( ! isset($wpdb) || ! $wpdb instanceof \wpdb || ! is_array($expectedState) ) {
                throw new \RuntimeException('Provider evidence conditional persistence unavailable.');
            }

            $updated = $wpdb->update(
                $wpdb->options,
                ['option_value' => maybe_serialize($state)],
                [
                    'option_name' => ProviderContract::STORE_OPTION,
                    'option_value' => maybe_serialize($expectedState),
                ],
                ['%s'],
                ['%s', '%s']
            );
            if ( 1 !== $updated ) {
                throw new \RuntimeException('Provider evidence persistence conflict.');
            }
            $this->clearStoreCache();
        }

        $persisted = get_option(ProviderContract::STORE_OPTION, null);
        if ( $persisted !== $state ) {
            throw new \RuntimeException('Provider evidence persistence failed.');
        }
    }

    private function deleteStoreIfUnchanged(array $expectedState): void {
        global $wpdb;

        if ( ! isset($wpdb) || ! $wpdb instanceof \wpdb ) {
            throw new \RuntimeException('Provider evidence conditional reset unavailable.');
        }

        $deleted = $wpdb->delete(
            $wpdb->options,
            [
                'option_name' => ProviderContract::STORE_OPTION,
                'option_value' => maybe_serialize($expectedState),
            ],
            ['%s', '%s']
        );
        if ( 1 !== $deleted ) {
            throw new \RuntimeException('Provider evidence reset conflict.');
        }
        $this->clearStoreCache();

        $missing = new \stdClass();
        if ( get_option(ProviderContract::STORE_OPTION, $missing) !== $missing ) {
            throw new \RuntimeException('Provider evidence reset failed.');
        }
    }

    private function clearStoreCache(): void {
        if ( function_exists('wp_cache_delete') ) {
            wp_cache_delete(ProviderContract::STORE_OPTION, 'options');
        }
    }

    private function acquireLock(): ?string {
        if ( ! function_exists('add_option') || ! function_exists('get_option') ) {
            return null;
        }

        $key = $this->lockKey();
        $owner = (string) ($this->lockTokenFactory)();
        if ( 1 !== preg_match('/^plk-[a-f0-9]{24}$/', $owner) ) {
            return null;
        }

        for ( $attempt = 0; $attempt < self::LOCK_ATTEMPTS; ++$attempt ) {
            $now = ($this->clock)();
            $lock = [
                'owner' => $owner,
                'expires_at' => $now + self::LOCK_TTL,
            ];

            if ( add_option($key, $lock, '', false) ) {
                return $owner;
            }

            $observed = get_option($key, false);
            if ( $this->isStaleLock($observed, $now) ) {
                $this->deleteLockIfMatches($key, $observed);
                continue;
            }

            if ( $attempt + 1 < self::LOCK_ATTEMPTS ) {
                ($this->sleeper)(self::LOCK_RETRY_US);
            }
        }

        return null;
    }

    private function ownsActiveLock(string $owner): bool {
        if ( ! function_exists('get_option') ) {
            return false;
        }

        $observed = get_option($this->lockKey(), false);
        return is_array($observed)
            && is_string($observed['owner'] ?? null)
            && hash_equals($owner, $observed['owner'])
            && is_int($observed['expires_at'] ?? null)
            && $observed['expires_at'] > ($this->clock)();
    }

    private function releaseLock(string $owner): bool {
        if ( ! function_exists('get_option') ) {
            return false;
        }

        $key = $this->lockKey();
        $observed = get_option($key, false);
        if ( ! is_array($observed) || ! is_string($observed['owner'] ?? null) || ! hash_equals($owner, $observed['owner']) ) {
            return false;
        }

        return $this->deleteLockIfMatches($key, $observed);
    }

    private function deleteLockIfMatches(string $key, mixed $expected): bool {
        global $wpdb;

        if ( ! isset($wpdb) || ! $wpdb instanceof \wpdb || ! is_array($expected) ) {
            return false;
        }

        $deleted = $wpdb->delete(
            $wpdb->options,
            [
                'option_name' => $key,
                'option_value' => maybe_serialize($expected),
            ],
            ['%s', '%s']
        );

        if ( 1 !== $deleted ) {
            return false;
        }

        if ( function_exists('wp_cache_delete') ) {
            wp_cache_delete($key, 'options');
        }

        return true;
    }

    private function isStaleLock(mixed $lock, int $now): bool {
        return is_array($lock)
            && is_int($lock['expires_at'] ?? null)
            && $lock['expires_at'] <= $now;
    }

    private function lockKey(): string {
        return ProviderContract::STORE_OPTION . '_lock';
    }

    private function probe(string $stage, array $context): void {
        if ( null !== $this->transactionProbe ) {
            ($this->transactionProbe)($stage, $context);
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
