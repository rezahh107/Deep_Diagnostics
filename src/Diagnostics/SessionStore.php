<?php
declare(strict_types=1);

namespace WDDTF\Diagnostics;

use Closure;
use Throwable;
use WDDTF\Privacy\Redactor;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class SessionStore {
    private const KEY_PREFIX = 'wddtf_diag_session_';
    private const LOCK_PREFIX = 'wddtf_diag_lock_';
    private const INTEGRITY_PREFIX = 'wddtf_diag_integrity_';
    private const LOCK_TTL = 30;
    private const LOCK_ATTEMPTS = 8;
    private const LOCK_RETRY_US = 25000;
    private const INTEGRITY_TTL = 900;

    private Closure $clock;
    private Closure $idFactory;
    private Closure $lockTokenFactory;
    private Closure $sleeper;

    public function __construct(
        ?callable $clock = null,
        ?callable $idFactory = null,
        ?callable $lockTokenFactory = null,
        ?callable $sleeper = null
    ) {
        $this->clock = Closure::fromCallable($clock ?? static fn(): int => time());
        $this->idFactory = Closure::fromCallable(
            $idFactory ?? static fn(): string => 'ds-' . bin2hex(random_bytes(8))
        );
        $this->lockTokenFactory = Closure::fromCallable(
            $lockTokenFactory ?? static fn(): string => 'lk-' . bin2hex(random_bytes(12))
        );
        $this->sleeper = Closure::fromCallable(
            $sleeper ?? static function(int $microseconds): void {
                usleep($microseconds);
            }
        );
    }

    public function create(string $type, array $data, int $ttl = 43200): ?array {
        $type = sanitize_key($type);
        if ( '' === $type ) {
            return null;
        }

        $now = ($this->clock)();
        $ttl = max(60, min($ttl, DAY_IN_SECONDS));
        $id  = (string) ($this->idFactory)();

        if ( ! $this->isValidId($id) ) {
            return null;
        }

        $session = [
            'id'         => $id,
            'type'       => $type,
            'created_at' => $now,
            'expires_at' => $now + $ttl,
            'data'       => ( new Redactor() )->redact($data),
        ];

        if ( ! set_transient($this->key($id), $session, $ttl) ) {
            return null;
        }

        return $session;
    }

    public function load(string $id): ?array {
        if ( ! $this->isValidId($id) ) {
            return null;
        }

        $session = get_transient($this->key($id));
        if ( false === $session || ! $this->isValidSession($session, $id) ) {
            return null;
        }

        if ( (int) $session['expires_at'] <= ($this->clock)() ) {
            return null;
        }

        return $session;
    }

    public function save(array $session): bool {
        $id = is_string($session['id'] ?? null) ? $session['id'] : '';
        if ( ! $this->isValidSession($session, $id) ) {
            return false;
        }

        $remaining = (int) $session['expires_at'] - ($this->clock)();
        if ( $remaining <= 0 ) {
            $this->delete($id);
            return false;
        }

        $session['data'] = ( new Redactor() )->redact($session['data']);

        return set_transient($this->key($id), $session, $remaining);
    }

    /**
     * Serializes a whole-session mutation behind one per-session lock.
     *
     * The callback receives state reloaded only after lock ownership is established and
     * must return the complete next session. Persistence still flows through save(), so
     * Redactor remains the single Diagnostic Session privacy boundary.
     */
    public function mutate(string $id, callable $mutation): bool {
        if ( ! $this->isValidId($id) ) {
            return false;
        }

        $owner = $this->acquireLock($id);
        if ( null === $owner ) {
            $this->markIntegrityUncertain($id, 'mutation_lock_unavailable');
            return false;
        }

        $committed = false;
        $failureReason = null;

        try {
            // This load intentionally happens only after exclusive ownership is established.
            $session = $this->load($id);
            if ( ! is_array($session) ) {
                $failureReason = 'mutation_session_unavailable';
            } else {
                $next = $mutation($session);
                if ( ! is_array($next) || ! $this->isValidSession($next, $id) ) {
                    $failureReason = 'mutation_callback_invalid';
                } elseif ( ! $this->ownsActiveLock($id, $owner) ) {
                    // Never commit after the lease expired or ownership moved to another writer.
                    $failureReason = 'mutation_lock_lost';
                } elseif ( ! $this->save($next) ) {
                    $failureReason = 'mutation_persistence_failed';
                } else {
                    $committed = true;
                }
            }
        } catch (Throwable) {
            $failureReason = 'mutation_callback_failed';
        }

        $released = $this->releaseLock($id, $owner);
        if ( ! $released && null === $failureReason ) {
            $committed = false;
            $failureReason = 'mutation_lock_release_failed';
        }

        if ( null !== $failureReason ) {
            $this->markIntegrityUncertain($id, $failureReason);
        }

        return $committed;
    }

    public function markIntegrityUncertain(string $id, string $reason): bool {
        if ( ! $this->isValidId($id) ) {
            return false;
        }

        $reason = sanitize_key($reason);
        if ( '' === $reason ) {
            $reason = 'unknown_integrity_failure';
        }

        $current = get_transient($this->integrityKey($id));
        if ( is_array($current) && ! empty($current['uncertain']) ) {
            return true;
        }

        return set_transient(
            $this->integrityKey($id),
            [
                'uncertain' => true,
                'reason'    => $reason,
                'marked_at' => ($this->clock)(),
            ],
            self::INTEGRITY_TTL
        );
    }

    public function integrityStatus(string $id): array {
        if ( ! $this->isValidId($id) ) {
            return [
                'uncertain' => true,
                'reason'    => 'invalid_session_id',
                'marked_at' => null,
            ];
        }

        $marker = get_transient($this->integrityKey($id));
        if ( ! is_array($marker) || empty($marker['uncertain']) ) {
            return [
                'uncertain' => false,
                'reason'    => null,
                'marked_at' => null,
            ];
        }

        return [
            'uncertain' => true,
            'reason'    => is_string($marker['reason'] ?? null) ? $marker['reason'] : 'unknown_integrity_failure',
            'marked_at' => is_int($marker['marked_at'] ?? null) ? $marker['marked_at'] : null,
        ];
    }

    public function delete(string $id): void {
        if ( $this->isValidId($id) ) {
            delete_transient($this->key($id));
            delete_transient($this->integrityKey($id));
        }
    }

    private function acquireLock(string $id): ?string {
        if ( ! function_exists('add_option') || ! function_exists('get_option') ) {
            return null;
        }

        $key = $this->lockKey($id);
        $owner = (string) ($this->lockTokenFactory)();
        if ( 1 !== preg_match('/^lk-[a-f0-9]{24}$/', $owner) ) {
            return null;
        }

        for ( $attempt = 0; $attempt < self::LOCK_ATTEMPTS; ++$attempt ) {
            $now = ($this->clock)();
            $lock = [
                'owner'      => $owner,
                'expires_at' => $now + self::LOCK_TTL,
            ];

            if ( add_option($key, $lock, '', false) ) {
                return $owner;
            }

            $observed = get_option($key, false);
            if ( $this->isStaleLock($observed, $now) ) {
                // Compare-delete prevents a stale observer from deleting a replacement lock.
                $this->deleteLockIfMatches($key, $observed);
                continue;
            }

            if ( $attempt + 1 < self::LOCK_ATTEMPTS ) {
                ($this->sleeper)(self::LOCK_RETRY_US);
            }
        }

        return null;
    }

    private function ownsActiveLock(string $id, string $owner): bool {
        if ( ! function_exists('get_option') ) {
            return false;
        }

        $observed = get_option($this->lockKey($id), false);
        return is_array($observed)
            && is_string($observed['owner'] ?? null)
            && hash_equals($owner, $observed['owner'])
            && is_int($observed['expires_at'] ?? null)
            && $observed['expires_at'] > ($this->clock)();
    }

    private function releaseLock(string $id, string $owner): bool {
        if ( ! function_exists('get_option') ) {
            return false;
        }

        $key = $this->lockKey($id);
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
                'option_name'  => $key,
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
        if ( ! is_array($lock) ) {
            return false;
        }

        return is_int($lock['expires_at'] ?? null) && $lock['expires_at'] <= $now;
    }

    private function isValidSession(mixed $session, string $expectedId): bool {
        return is_array($session)
            && isset($session['id'], $session['type'], $session['created_at'], $session['expires_at'], $session['data'])
            && is_string($session['id'])
            && hash_equals($expectedId, $session['id'])
            && $this->isValidId($session['id'])
            && is_string($session['type'])
            && '' !== $session['type']
            && is_int($session['created_at'])
            && is_int($session['expires_at'])
            && $session['expires_at'] > $session['created_at']
            && is_array($session['data']);
    }

    private function isValidId(string $id): bool {
        return 1 === preg_match('/^ds-[a-f0-9]{16}$/', $id);
    }

    private function key(string $id): string {
        return self::KEY_PREFIX . substr(hash('sha256', $id), 0, 32);
    }

    private function lockKey(string $id): string {
        return self::LOCK_PREFIX . substr(hash('sha256', $id), 0, 32);
    }

    private function integrityKey(string $id): string {
        return self::INTEGRITY_PREFIX . substr(hash('sha256', $id), 0, 32);
    }
}
