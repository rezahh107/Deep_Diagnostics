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
 * The request-local context exists before request classification is authoritative, but it
 * exposes no reference until DEEP explicitly promotes that execution to supported. Unknown
 * and unsupported request classes therefore fail closed at the public Provider seam.
 */
final class ExecutionCorrelationContext {
    private const PREFIX = 'dx1_';
    private const RANDOM_BYTES = 12;
    private const REFERENCE_PATTERN = '/^dx1_[A-Za-z0-9_-]{16}$/D';
    private const STATE_UNKNOWN = 'unknown';
    private const STATE_SUPPORTED = 'supported';
    private const STATE_UNSUPPORTED = 'unsupported';

    private static ?self $active = null;

    private Closure $randomBytes;
    private string $state = self::STATE_UNKNOWN;
    private bool $generationAttempted = false;
    private ?string $reference = null;

    public function __construct(?callable $randomBytes = null) {
        $this->randomBytes = Closure::fromCallable(
            $randomBytes ?? static fn(int $length): string => random_bytes($length)
        );
    }

    /**
     * Makes this request-local context visible without assuming UNKNOWN means supported.
     *
     * The optional boolean is retained for internal/test compatibility: true performs an
     * explicit supported transition; false performs an explicit unsupported transition.
     */
    public function activate(?bool $supportedExecution = null): ?string {
        self::$active = $this;

        if ( true === $supportedExecution ) {
            return $this->support();
        }

        if ( false === $supportedExecution ) {
            $this->markUnsupported();
        }

        return null;
    }

    /**
     * Promotes UNKNOWN to SUPPORTED and establishes the reference exactly once.
     * An UNSUPPORTED context can never be promoted.
     */
    public function support(): ?string {
        if ( self::STATE_UNSUPPORTED === $this->state ) {
            return null;
        }

        if ( self::STATE_UNKNOWN === $this->state ) {
            $this->state = self::STATE_SUPPORTED;
        }

        return $this->establish();
    }

    /**
     * Marks an unresolved request as unsupported. A supported context is never revoked:
     * lifecycle coordination must establish support only after authoritative classification.
     */
    public function markUnsupported(): void {
        if ( self::STATE_UNKNOWN === $this->state ) {
            $this->state = self::STATE_UNSUPPORTED;
        }
    }

    public function current(): ?string {
        if ( self::STATE_SUPPORTED !== $this->state ) {
            return null;
        }

        return $this->reference;
    }

    public static function activeReference(): ?string {
        return self::$active?->current();
    }

    public static function isValidReference(mixed $reference): bool {
        return is_string($reference)
            && 1 === preg_match(self::REFERENCE_PATTERN, $reference);
    }

    /**
     * Generates at most once after an authoritative SUPPORTED transition. Any generator
     * failure or malformed entropy fails closed and never falls back to request metadata.
     */
    private function establish(): ?string {
        if ( self::STATE_SUPPORTED !== $this->state ) {
            return null;
        }

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
}
