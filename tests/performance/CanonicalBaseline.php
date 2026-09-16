<?php
declare(strict_types=1);

final class WddtfPerformanceCanonicalBaseline {
    private function __construct(
        private readonly bool $saveQueries,
        private readonly bool $externalHttpBlocked,
    ) {
    }

    public static function fromObservedRuntime(bool $saveQueries, bool $externalHttpBlocked): self {
        $violations = [];

        if ( $saveQueries ) {
            $violations[] = 'SAVEQUERIES must be disabled';
        }
        if ( ! $externalHttpBlocked ) {
            $violations[] = 'WP_HTTP_BLOCK_EXTERNAL must be true';
        }

        if ( [] !== $violations ) {
            throw new RuntimeException(
                'Noncanonical performance benchmark baseline: ' . implode('; ', $violations) . '.'
            );
        }

        return new self($saveQueries, $externalHttpBlocked);
    }

    /** @return array{savequeries:bool,external_http_blocked:bool} */
    public function environmentFields(): array {
        return [
            'savequeries'            => $this->saveQueries,
            'external_http_blocked' => $this->externalHttpBlocked,
        ];
    }
}
