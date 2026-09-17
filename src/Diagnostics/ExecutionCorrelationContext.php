<?php
declare(strict_types=1);

namespace WDDTF\Diagnostics;

use Closure;
use Throwable;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Owns the opaque correlation identity for one supported DEEP-observed PHP execution.
 *
 * The reference is intentionally request-local. It contains only random bytes encoded as
 * a short URL-safe token and never falls back to request, user, site, time, or database data.
 */
final class ExecutionCorrelationContext {
    private const PREFIX = 'dx1_';
    private const RANDOM_BYTES = 12;
    private const REFERENCE_PATTERN = '/^dx1_[A-Za-z0-9_-]{16}$/D';

    private static ?self $active = null;

    private Closure $randomBytes;
    private bool $generationAttempted = false;
    private ?string $reference = null;

    public function __construct(?callable $randomBytes = null) {
        $this->randomBytes = Closure::fromCallable(
            $randomBytes ?? static fn(int $length): string => random_bytes($length)
        );
    }

    /**
     * Makes this context visible to read-only same-request integration accessors.
     * Unsupported request types deliberately expose no fabricated reference.
     */
    public function activate(bool $supportedExecution): ?string {
        self::$active = $this;

        return $supportedExecution ? $this->establish() : null;
    }

    /**
     * Generates at most once. Any generator failure or malformed entropy fails closed.
     */
    public function establish(): ?string {
        if ( $this->generationAttempted ) {
            return $this->reference;
        }

        $this->generationAttempted = true;

        try {
            $bytes = ($this->randomBytes)(self::RANDOM_BYTES);
        } catch (Throwable) {
            return null;
        }

        if ( ! is_string($bytes) || self::RANDOM_BYTES !== strlen($bytes) ) {
            return null;
        }

        $candidate = self::PREFIX . rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        if ( ! self::isValidReference($candidate) ) {
            return null;
        }

        $this->reference = $candidate;

        return $this->reference;
    }

    public function current(): ?string {
        return $this->reference;
    }

    public static function activeReference(): ?string {
        return self::$active?->current();
    }

    public static function isValidReference(mixed $reference): bool {
        return is_string($reference)
            && 1 === preg_match(self::REFERENCE_PATTERN, $reference);
    }
}
