<?php
declare(strict_types=1);

namespace WDDTF\Collectors;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class EventCollector {
    private array $events = [];
    private float $lastTime = 0.0;
    private array $hookCounts = [];
    private const MAX = 200;

    public function record(string $hook): void {
        $now = microtime(true);

        $this->hookCounts[$hook] = ($this->hookCounts[$hook] ?? 0) + 1;

        $elapsed = null;
        if ( $this->lastTime > 0 ) {
            $elapsed = $now - $this->lastTime;
        }

        if ( count($this->events) >= self::MAX ) {
            array_shift($this->events);
        }

        $eventData = [
            'did_action' => $this->hookCounts[$hook],
            'memory'     => memory_get_usage(true),
            'time'       => $now,
        ];

        if ( $elapsed !== null ) {
            $eventData['elapsed_since_prev'] = $elapsed;
        }

        $this->events[] = [
            'layer' => $hook,
            'data'  => $eventData,
        ];

        $this->lastTime = $now;
    }

    public function recordCustom(string $layer, array $data): void {
        $now = microtime(true);

        if ( $this->lastTime > 0 ) {
            $data['elapsed_since_prev'] = $now - $this->lastTime;
        }

        if ( count($this->events) >= self::MAX ) {
            array_shift($this->events);
        }

        $this->events[] = [
            'layer' => $layer,
            'data'  => $data + ['time' => $now],
        ];

        $this->lastTime = $now;
    }

    public function snapshot(): array {
        return $this->events;
    }
}
