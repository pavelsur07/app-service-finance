<?php

namespace App\Analytics\Infrastructure\Telemetry;

final class SnapshotTelemetry
{
    private const GLOBAL_TIMER = '__global__';

    /** @var array<string, float> */
    private array $startedAt = [];

    /** @var array<string, int> */
    private array $durationsMs = [];

    public function start(string $widget): void
    {
        $this->startedAt[$widget] = microtime(true);
    }

    public function stop(string $widget): void
    {
        $startedAt = $this->startedAt[$widget] ?? null;
        if (null === $startedAt) {
            return;
        }

        $this->durationsMs[$widget] = (int) round((microtime(true) - $startedAt) * 1000);
        unset($this->startedAt[$widget]);
    }

    /**
     * @return array{total_duration_ms: int, widgets_duration_ms: array<string, int>}
     */
    public function finish(): array
    {
        $globalStartedAt = $this->startedAt[self::GLOBAL_TIMER] ?? null;
        $totalDurationMs = null === $globalStartedAt
            ? ((int) ($this->durationsMs[self::GLOBAL_TIMER] ?? 0))
            : (int) round((microtime(true) - $globalStartedAt) * 1000);

        $widgetsDurationMs = $this->durationsMs;
        unset($widgetsDurationMs[self::GLOBAL_TIMER]);

        return [
            'total_duration_ms' => $totalDurationMs,
            'widgets_duration_ms' => $widgetsDurationMs,
        ];
    }

    public static function globalTimerName(): string
    {
        return self::GLOBAL_TIMER;
    }
}

