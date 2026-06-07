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
            'url'   => $url,
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

        $this->requests[] = [
            'url'      => $url,
            'duration' => $duration,
            'blocking' => $info['blocking'] ?? true,
            'result'   => is_wp_error($response) ? $response->get_error_message() : (is_array($response) ? array_keys($response) : gettype($response)),
            'args'     => $info,
        ];
    }

    private function fingerprint(string $url, array $args): string {
        $data = [
            'url'      => $url,
            'method'   => $args['method'] ?? 'GET',
            'blocking' => $args['blocking'] ?? true,
            'timeout'  => $args['timeout'] ?? 5,
            'headers'  => isset($args['headers']) ? (array) $args['headers'] : [],
            'body'     => $args['body'] ?? null,
            'httpversion' => $args['httpversion'] ?? '1.0',
        ];

        return md5((string) wp_json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_SORT_KEYS));
    }

    public function snapshot(): array {
        return $this->requests;
    }
}
