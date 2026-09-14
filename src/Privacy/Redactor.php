<?php
declare(strict_types=1);

namespace WDDTF\Privacy;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class Redactor {
    private const REDACTED = '[redacted]';

    public function redact(array $payload): array {
        return $this->walk($payload);
    }

    private function walk(mixed $value, ?string $key = null): mixed {
        if ( is_array($value) ) {
            $clean = [];
            foreach ( $value as $childKey => $childValue ) {
                $clean[$childKey] = $this->walk($childValue, is_string($childKey) ? strtolower($childKey) : null);
            }
            return $clean;
        }

        if ( ! is_string($value) ) {
            return $value;
        }

        if ( $this->isSensitiveKey($key) ) {
            return self::REDACTED;
        }

        return match ( $key ) {
            'sql' => $this->redactSql($value),
            'url', 'uri', 'src' => $this->redactUrlLike($value),
            default => $this->redactText($value),
        };
    }

    private function isSensitiveKey(?string $key): bool {
        if ( null === $key ) {
            return false;
        }

        return in_array(
            $key,
            [
                'authorization',
                'cookie',
                'cookies',
                'password',
                'passwd',
                'secret',
                'token',
                'api_key',
                'apikey',
                'access_token',
                'refresh_token',
                'remote_addr',
                'ip_address',
                'email',
            ],
            true
        );
    }

    private function redactUrlLike(string $value): string {
        $parts = parse_url($value);

        if ( false === $parts ) {
            return $this->redactText($value);
        }

        if ( str_starts_with($value, '/') ) {
            return $this->redactPath((string) ($parts['path'] ?? '/'));
        }

        if ( empty($parts['host']) ) {
            return $this->redactText(strtok($value, '?#') ?: '');
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) . '://' : '';
        $host   = strtolower((string) $parts['host']);
        $port   = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path   = $this->redactPath((string) ($parts['path'] ?? '/'));

        return $scheme . $host . $port . $path;
    }

    private function redactPath(string $path): string {
        $segments = explode('/', $path);

        foreach ( $segments as &$segment ) {
            if ( '' === $segment ) {
                continue;
            }

            $decoded = rawurldecode($segment);
            if (
                preg_match('/^\d+$/', $decoded) ||
                preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27,}$/i', $decoded) ||
                filter_var($decoded, FILTER_VALIDATE_EMAIL) ||
                preg_match('/^(?=.{20,}$)(?=.*[A-Za-z])(?=.*\d)[A-Za-z0-9_-]+$/', $decoded)
            ) {
                $segment = '[id]';
            }
        }
        unset($segment);

        return implode('/', $segments);
    }

    private function redactSql(string $sql): string {
        $sql = preg_replace("/'(?:''|\\\\.|[^'])*'/s", "'?'", $sql) ?? $sql;
        $sql = preg_replace('/"(?:""|\\\\.|[^"])*"/s', '"?"', $sql) ?? $sql;
        $sql = preg_replace('/(?<![A-Za-z0-9_])\d+(?:\.\d+)?(?![A-Za-z0-9_])/', '?', $sql) ?? $sql;

        return $this->redactText($sql);
    }

    private function redactText(string $text): string {
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer ' . self::REDACTED, $text) ?? $text;
        $text = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[redacted-email]', $text) ?? $text;
        $text = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[redacted-ip]', $text) ?? $text;
        $text = preg_replace('/\b(api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret|password|passwd)\s*[:=]\s*[^\s,;&]+/i', '$1=' . self::REDACTED, $text) ?? $text;
        $text = preg_replace_callback(
            '~https?://[^\s<>"\']+~i',
            fn(array $match): string => $this->redactUrlLike($match[0]),
            $text
        ) ?? $text;
        $text = preg_replace('/\b(?=[A-Za-z0-9_-]{24,}\b)(?=[A-Za-z0-9_-]*[A-Za-z])(?=[A-Za-z0-9_-]*\d)[A-Za-z0-9_-]+\b/', '[redacted-token]', $text) ?? $text;

        return $text;
    }
}
