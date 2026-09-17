<?php
declare(strict_types=1);

namespace WDDTF\Providers;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class EvidenceSanitizer {
    public function token(mixed $value, int $maxLength = 160): ?string {
        if ( ! is_string($value) || '' === $value || strlen($value) > $maxLength ) {
            return null;
        }
        if ( 1 !== preg_match('/^[A-Za-z0-9._:+\/-]+$/', $value) ) {
            return null;
        }
        if ( str_contains($value, '..') || str_starts_with($value, '/') || str_contains($value, '://') || str_contains($value, '@') ) {
            return null;
        }
        return $value;
    }

    public function label(mixed $value, int $maxLength = 160): ?string {
        if ( ! is_string($value) ) {
            return null;
        }
        $value = trim($value);
        if ( '' === $value || strlen($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) ) {
            return null;
        }
        if ( filter_var($value, FILTER_VALIDATE_EMAIL) || str_contains($value, '://') ) {
            return null;
        }
        return $value;
    }

    public function version(mixed $value): ?string {
        if ( ! is_string($value) || '' === $value || strlen($value) > 64 ) {
            return null;
        }
        if ( 1 !== preg_match('/^[A-Za-z0-9._+-]+$/', $value) ) {
            return null;
        }

        // The central Redactor intentionally treats bare four-octet dotted numbers as
        // possible IP addresses. Canonicalize legitimate four-part version metadata with
        // an explicit v-prefix before that privacy boundary so e.g. Gravity Forms 3.1.1.1
        // remains useful evidence without weakening generic IP redaction.
        if ( 1 === preg_match('/^\d+(?:\.\d+){3}$/', $value) ) {
            return 'v' . $value;
        }

        return $value;
    }

    public function timestamp(mixed $value): ?string {
        if ( ! is_string($value) || strlen($value) > 40 ) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ( false === $date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ) {
            return null;
        }
        return $date->format(\DateTimeInterface::ATOM);
    }

    public function privacyBoundary(mixed $value): array {
        if ( ! is_array($value) ) {
            return [];
        }
        $result = [];
        foreach ( array_slice($value, 0, 20, true) as $key => $state ) {
            if ( ! is_string($key) || ! is_string($state) ) {
                continue;
            }
            $safeKey = sanitize_key($key);
            if ( '' === $safeKey || $safeKey !== $key || strlen($safeKey) > 80 ) {
                continue;
            }
            if ( 1 !== preg_match('/^[A-Z][A-Z0-9_]{1,47}$/', $state) ) {
                continue;
            }
            $result[$safeKey] = $state;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    public function incidents(mixed $records): array {
        if ( ! is_array($records) ) {
            return [];
        }
        $result = [];
        foreach ( array_slice(array_values($records), -ProviderContract::MAX_INCIDENTS) as $record ) {
            $normalized = $this->trace($record, false);
            if ( null !== $normalized ) {
                $result[] = $normalized;
            }
        }
        return $result;
    }

    public function recentSuccess(mixed $records): array {
        if ( ! is_array($records) ) {
            return [];
        }
        $result = [];
        foreach ( array_slice($records, 0, ProviderContract::MAX_RECENT_SUCCESS, true) as $surface => $record ) {
            $normalized = $this->trace($record, true);
            if ( null === $normalized ) {
                continue;
            }
            $normalized['surface'] = $normalized['surface'] ?? $this->token(is_string($surface) ? $surface : null, 96);
            if ( null !== $normalized['surface'] ) {
                $result[] = $normalized;
            }
        }
        return $result;
    }

    public function trace(mixed $record, bool $successOnly): ?array {
        if ( ! is_array($record) ) {
            return null;
        }
        $surface = $this->token($record['surface'] ?? null, 96);
        $status = $this->token($record['status'] ?? null, 32);
        $observedAt = $this->timestamp($record['observed_at_utc'] ?? null);
        if ( null === $surface || null === $status || null === $observedAt ) {
            return null;
        }
        $allowedStatus = $successOnly ? ['PASS'] : ['FAIL', 'DEGRADED'];
        if ( ! in_array($status, $allowedStatus, true) ) {
            return null;
        }

        $events = [];
        if ( is_array($record['events'] ?? null) ) {
            foreach ( array_slice($record['events'], 0, ProviderContract::MAX_INCIDENT_EVENTS) as $event ) {
                if ( ! is_array($event) ) {
                    continue;
                }
                $stage = $this->token($event['stage'] ?? null, 96);
                $eventResult = $this->token($event['result'] ?? null, 32);
                if ( null === $stage || ! in_array($eventResult, ['PASS', 'SKIP', 'FAIL', 'NOT_APPLICABLE'], true) ) {
                    continue;
                }
                $events[] = [
                    'seq' => isset($event['seq']) && is_int($event['seq']) && $event['seq'] > 0 ? $event['seq'] : count($events) + 1,
                    'stage' => $stage,
                    'result' => $eventResult,
                    'reason_code' => $this->token($event['reason_code'] ?? null, 96),
                    'fallback' => $this->token($event['fallback'] ?? null, 96),
                ];
            }
        }

        $firstInconsistent = null;
        foreach ( $events as $event ) {
            if ( in_array($event['result'], ['FAIL', 'SKIP'], true) ) {
                $firstInconsistent = [
                    'stage' => $event['stage'],
                    'result' => $event['result'],
                    'reason_code' => $event['reason_code'],
                    'fallback' => $event['fallback'],
                ];
                break;
            }
        }

        return [
            'schema_version' => $this->version($record['schema_version'] ?? null),
            'surface' => $surface,
            'status' => $status,
            'observed_at_utc' => $observedAt,
            'events' => $events,
            'first_inconsistent_boundary' => $firstInconsistent,
            'correlation_ref' => $this->token($record['correlation_ref'] ?? null, 128),
        ];
    }
}
