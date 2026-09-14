<?php
declare(strict_types=1);

namespace WDDTF\Collectors;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class HttpCollector {
    private array $requests = [];
    private array $pending = [];
    private const MAX = 200;

    public function pre(string $url, array $args): void {
        $key = $this->fingerprint($url, $args);

        $this->pending[$key] = [
            'start' => microtime(true),
            'args'  => [
                'method'   => isset($args['method']) ? sanitize_text_field((string) $args['method']) : 'GET',
                'blocking' => isset($args['blocking']) ? (bool) $args['blocking'] : true,
                'timeout'  => isset($args['timeout']) ? (float) $args['timeout'] : 5.0,
                'headers'  => isset($args['headers']) ? array_keys((array) $args['headers']) : [],
            ],
        ];
    }

    public function debug(mixed $response, array $args, string $url): void {
        $key = $this->fingerprint($url, $args);

        if ( ! isset($this->pending[$key]) ) {
            return;
        }

        $info     = $this->pending[$key]['args'];
        $start    = $this->pending[$key]['start'];
        $duration = microtime(true) - $start;

        unset($this->pending[$key]);

        if ( count($this->requests) >= self::MAX ) {
            array_shift($this->requests);
        }

        $result = is_wp_error($response)
            ? 'wp_error:' . sanitize_text_field((string) $response->get_error_code())
            : (is_array($response) ? array_keys($response) : gettype($response));

        $this->requests[] = [
            'url'      => $url,
            'duration' => $duration,
            'blocking' => $info['blocking'] ?? true,
            'result'   => $result,
            'args'     => $info,
        ];
    }

    private function fingerprint(string $url, array $args): string {
        $headers = isset($args['headers']) ? array_map('strtolower', array_keys((array) $args['headers'])) : [];
        sort($headers, SORT_STRING);

        $body     = $args['body'] ?? null;
        $bodyHash = null === $body
            ? null
            : hash('sha256', is_scalar($body) ? (string) $body : (string) wp_json_encode($body));

        $data = [
            'url'         => $url,
            'method'      => $args['method'] ?? 'GET',
            'blocking'    => $args['blocking'] ?? true,
            'timeout'     => $args['timeout'] ?? 5,
            'headers'     => $headers,
            'body_hash'   => $bodyHash,
            'httpversion' => $args['httpversion'] ?? '1.0',
        ];

        return md5((string) wp_json_encode($data, \JSON_UNESCAPED_SLASHES));
    }

    public function snapshot(): array {
        return $this->requests;
    }
}
