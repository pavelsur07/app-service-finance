<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Performance;

/**
 * Агрегирует диагностические события M1 в отчёт: перцентили по этапам и провайдерам,
 * очередь по транспортам, время обработчиков по типам сообщений и полноту замеров.
 *
 * Перцентиль — nearest-rank по значениям событий. Событие этапа — сумма времени этапа
 * внутри одного сообщения/запуска, поэтому p95 этапа — это «95% сообщений тратят на
 * этап не больше», а не латентность одного HTTP-запроса или одного flush.
 */
final class PerformanceReportBuilder
{
    /** @var array<string, array{stage: string, provider: string, backend: ?string, durations: list<float>, calls: int, rows: int, rowsMissing: int, bytes: int, bytesSeen: bool, durationForRows: float, durationForBytes: float, errors: int, memoryPeak: ?int, outcomes: array<string, int>}> */
    private array $stages = [];

    /** @var array<string, array{transport: string, lags: list<float>, unavailable: int, redelivered: int}> */
    private array $queues = [];

    /** @var array<string, array{job: string, provider: string, durations: list<float>, failed: int, retried: int, unknown: int, memoryPeak: ?int, memoryDelta: ?int}> */
    private array $handlers = [];

    /**
     * Попытки по ключу `transport|trace`: id Redis уникален только внутри потока, а повторная
     * доставка своего pending сохраняет id — поэтому ключ с транспортом и счётчик, а не множество.
     *
     * @var array<string, int>
     */
    private array $tracesQueued = [];

    /** @var array<string, int> */
    private array $tracesHandled = [];

    private int $events = 0;
    private int $droppedEvents = 0;
    private int $durationMissing = 0;

    /**
     * @param array<string, mixed> $event
     */
    public function add(array $event): void
    {
        ++$this->events;
        $this->droppedEvents += self::int($event['dropped_events'] ?? null) ?? 0;

        $stage = (string) $event['stage'];
        $provider = self::str($event['provider'] ?? null) ?? 'none';
        $duration = self::float($event['duration_ms'] ?? null);
        $trace = self::str($event['trace'] ?? null);

        if (null === $duration) {
            ++$this->durationMissing;
        }

        if (PerformanceStage::QueueWait->value === $stage) {
            $transport = self::str($event['transport'] ?? null) ?? 'unknown';
            $queue = $this->queues[$transport] ?? ['transport' => $transport, 'lags' => [], 'unavailable' => 0, 'redelivered' => 0];
            if (null === $duration) {
                ++$queue['unavailable'];
            } else {
                $queue['lags'][] = $duration;
            }
            if ((self::int($event['retry_count'] ?? null) ?? 0) > 0) {
                ++$queue['redelivered'];
            }
            $this->queues[$transport] = $queue;
            if (null !== $trace) {
                $traceKey = $transport.'|'.$trace;
                $this->tracesQueued[$traceKey] = ($this->tracesQueued[$traceKey] ?? 0) + 1;
            }

            return;
        }

        $outcome = self::str($event['outcome'] ?? null) ?? 'unknown';
        $memory = self::int($event['memory_peak_bytes'] ?? null);

        if (PerformanceStage::Handler->value === $stage) {
            $job = self::str($event['job'] ?? null) ?? 'unknown';
            $key = $job.'|'.$provider;
            $handler = $this->handlers[$key] ?? ['job' => $job, 'provider' => $provider, 'durations' => [], 'failed' => 0, 'retried' => 0, 'unknown' => 0, 'memoryPeak' => null, 'memoryDelta' => null];
            if (null !== $duration) {
                $handler['durations'][] = $duration;
            }
            match ($outcome) {
                PerformanceOutcome::Error->value => ++$handler['failed'],
                PerformanceOutcome::Retry->value => ++$handler['retried'],
                PerformanceOutcome::Unknown->value => ++$handler['unknown'],
                default => null,
            };
            $handler['memoryPeak'] = self::max($handler['memoryPeak'], $memory);
            $base = self::int($event['memory_base_bytes'] ?? null);
            if (null !== $memory && null !== $base) {
                $handler['memoryDelta'] = self::max($handler['memoryDelta'], max(0, $memory - $base));
            }
            $this->handlers[$key] = $handler;
            if (null !== $trace) {
                $traceKey = (self::str($event['transport'] ?? null) ?? 'unknown').'|'.$trace;
                $this->tracesHandled[$traceKey] = ($this->tracesHandled[$traceKey] ?? 0) + 1;
            }

            return;
        }

        $backend = self::str($event['backend'] ?? null);
        $key = $stage.'|'.$provider.'|'.($backend ?? '');
        $group = $this->stages[$key] ?? [
            'stage' => $stage,
            'provider' => $provider,
            'backend' => $backend,
            'durations' => [],
            'calls' => 0,
            'rows' => 0,
            'rowsMissing' => 0,
            'bytes' => 0,
            'bytesSeen' => false,
            'durationForRows' => 0.0,
            'durationForBytes' => 0.0,
            'errors' => 0,
            'memoryPeak' => null,
            'outcomes' => [],
        ];

        if (null !== $duration) {
            $group['durations'][] = $duration;
        }
        $group['calls'] += self::int($event['calls'] ?? null) ?? 1;
        $group['errors'] += self::int($event['errors'] ?? null) ?? 0;
        $rows = self::int($event['rows'] ?? null);
        $bytes = self::int($event['bytes'] ?? null);
        if (null === $rows) {
            ++$group['rowsMissing'];
        } else {
            $group['rows'] += $rows;
        }
        if (null !== $bytes) {
            $group['bytes'] += $bytes;
            $group['bytesSeen'] = true;
        }
        // Свой знаменатель на каждую скорость: событие без rows не должно занижать rows/s.
        if (null !== $duration && null !== $rows) {
            $group['durationForRows'] += $duration;
        }
        if (null !== $duration && null !== $bytes) {
            $group['durationForBytes'] += $duration;
        }
        $group['memoryPeak'] = self::max($group['memoryPeak'], $memory);
        $group['outcomes'][$outcome] = ($group['outcomes'][$outcome] ?? 0) + 1;
        $this->stages[$key] = $group;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $stages = [];
        foreach ($this->stages as $group) {
            $rowSeconds = $group['durationForRows'] / 1000;
            $byteSeconds = $group['durationForBytes'] / 1000;
            $hasRows = \count($group['durations']) > $group['rowsMissing'];
            $stages[] = [
                'stage' => $group['stage'],
                'provider' => $group['provider'],
                'backend' => $group['backend'],
                'events' => \count($group['durations']),
                'calls' => $group['calls'],
                'duration_ms' => self::percentiles($group['durations']),
                'rows' => $hasRows ? $group['rows'] : null,
                'bytes' => $group['bytesSeen'] ? $group['bytes'] : null,
                'rows_per_sec' => $hasRows && $rowSeconds > 0 ? round($group['rows'] / $rowSeconds, 1) : null,
                'bytes_per_sec' => $group['bytesSeen'] && $byteSeconds > 0 ? round($group['bytes'] / $byteSeconds, 1) : null,
                'errors' => $group['errors'],
                'max_memory_peak_bytes' => $group['memoryPeak'],
                'rows_missing_events' => $group['rowsMissing'],
                'outcomes' => $group['outcomes'],
            ];
        }
        usort($stages, static fn (array $a, array $b): int => [$a['stage'], $a['provider'], $a['backend'] ?? ''] <=> [$b['stage'], $b['provider'], $b['backend'] ?? '']);

        $queues = [];
        foreach ($this->queues as $queue) {
            $queues[] = [
                'transport' => $queue['transport'],
                'messages' => \count($queue['lags']) + $queue['unavailable'],
                'lag_ms' => self::percentiles($queue['lags']),
                'lag_unavailable' => $queue['unavailable'],
                'redelivered' => $queue['redelivered'],
            ];
        }
        usort($queues, static fn (array $a, array $b): int => $a['transport'] <=> $b['transport']);

        $handlers = [];
        foreach ($this->handlers as $handler) {
            $handlers[] = [
                'job' => $handler['job'],
                'provider' => $handler['provider'],
                'messages' => \count($handler['durations']),
                'duration_ms' => self::percentiles($handler['durations']),
                'failed' => $handler['failed'],
                'retried' => $handler['retried'],
                'unknown_outcome' => $handler['unknown'],
                'max_memory_peak_bytes' => $handler['memoryPeak'],
                'max_memory_growth_bytes' => $handler['memoryDelta'],
            ];
        }
        usort($handlers, static fn (array $a, array $b): int => [$b['duration_ms']['p95'] ?? 0, $a['job']] <=> [$a['duration_ms']['p95'] ?? 0, $b['job']]);

        return [
            'events' => $this->events,
            'stages' => $stages,
            'queues' => $queues,
            'handlers' => $handlers,
            'completeness' => [
                // Сообщение взято из очереди, но завершения нет: воркер убит (OOM, SIGKILL,
                // деплой) или событие отброшено лимитом / за границей периода.
                'messages_without_handler_event' => self::excess($this->tracesQueued, $this->tracesHandled),
                'handler_events_without_queue_wait' => self::excess($this->tracesHandled, $this->tracesQueued),
                'events_without_duration' => $this->durationMissing,
                'dropped_events' => $this->droppedEvents,
            ],
        ];
    }

    /**
     * @param list<float> $values
     *
     * @return array{p50: ?float, p95: ?float, p99: ?float, max: ?float}
     */
    public static function percentiles(array $values): array
    {
        if ([] === $values) {
            return ['p50' => null, 'p95' => null, 'p99' => null, 'max' => null];
        }

        sort($values);
        $n = \count($values);
        $rank = static fn (float $p): float => $values[max(0, (int) ceil($p / 100 * $n) - 1)];

        return ['p50' => $rank(50), 'p95' => $rank(95), 'p99' => $rank(99), 'max' => $values[$n - 1]];
    }

    /**
     * Сколько попыток из $left не нашли пары в $right (по ключу и числу).
     *
     * @param array<string, int> $left
     * @param array<string, int> $right
     */
    private static function excess(array $left, array $right): int
    {
        $missing = 0;
        foreach ($left as $key => $count) {
            $missing += max(0, $count - ($right[$key] ?? 0));
        }

        return $missing;
    }

    private static function str(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return \is_int($value) ? $value : (\is_float($value) ? (int) $value : null);
    }

    private static function float(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }

    private static function max(?int $a, ?int $b): ?int
    {
        return null === $a ? $b : (null === $b ? $a : max($a, $b));
    }
}
