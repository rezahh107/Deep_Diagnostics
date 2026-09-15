<?php
declare(strict_types=1);

namespace WDDTF\Diagnostics;

use Closure;
use WDDTF\Privacy\Redactor;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class SessionStore {
    private const KEY_PREFIX = 'wddtf_diag_session_';

    private Closure $clock;
    private Closure $idFactory;

    public function __construct(?callable $clock = null, ?callable $idFactory = null) {
        $this->clock = Closure::fromCallable($clock ?? static fn(): int => time());
        $this->idFactory = Closure::fromCallable(
            $idFactory ?? static fn(): string => 'ds-' . bin2hex(random_bytes(8))
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
        if ( false === $session ) {
            return null;
        }

        if ( ! $this->isValidSession($session, $id) ) {
            $this->delete($id);
            return null;
        }

        if ( (int) $session['expires_at'] <= ($this->clock)() ) {
            $this->delete($id);
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

    public function delete(string $id): void {
        if ( $this->isValidId($id) ) {
            delete_transient($this->key($id));
        }
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
}
