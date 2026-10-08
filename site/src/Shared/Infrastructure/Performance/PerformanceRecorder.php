<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Performance;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Лёгкие диагностические замеры Marketplace M1 (docs/architecture/marketplace-audit/09-m1-diagnostics.md).
 *
 * Модель: «область» (scope) — одно сообщение воркера или один запуск `app:marketplace:*`
 * команды. Внутри области этапы копятся в памяти (сумма времени, вызовы, строки, байты,
 * ошибки) и пишутся одним событием на этап при закрытии области, плюс событие `handler`
 * на всю область. `queue_wait` пишется сразу при открытии — так сообщение, убитое по
 * OOM, видно в отчёте как неполное. Вне области ничего не замеряется: php-fpm и прочие
 * команды не платят ничего, кроме проверки флага.
 *
 * Гарантии:
 *  - выключенный флаг → `start()` возвращает null, остальное выходит после одной проверки;
 *  - сбой записи лога не прерывает бизнес-операцию: перехватывается, один warning в
 *    основной лог на процесс, дальше молча считается в `failedWrites`;
 *  - объём ограничен `maxEventsPerMinute` на процесс, отброшенное число уходит полем
 *    `dropped_events` со следующим событием;
 *  - кардинальность ограничена: provider — из списка, job/transport — по шаблону,
 *    company_id — только UUID. Значения строк, суммы, тела и токены сюда не попадают.
 */
final class PerformanceRecorder
{
    public const SCHEMA_VERSION = 1;
    public const LOG_MESSAGE = 'perf';
    public const PROVIDERS = ['ozon', 'wb', 'none'];

    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_.:\-]{1,80}$/';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const TRACE_PATTERN = '/^\d{1,20}(-\d{1,10})?$/';
    private const WINDOW_NS = 60_000_000_000;

    /** @var array{job: string, provider: string, companyId: ?string, transport: ?string, trace: ?string, retryCount: ?int, startedAt: int, memoryBase: int}|null */
    private ?array $scope = null;

    /** @var array<string, array{stage: PerformanceStage, provider: string, backend: ?string, ns: int, calls: int, rows: ?int, bytes: ?int, errors: int}> */
    private array $spans = [];

    private int $windowStartedAt = 0;
    private int $windowEvents = 0;
    private int $droppedEvents = 0;
    private int $failedWrites = 0;

    /**
     * Без аргументов — выключенный регистратор: умолчание для конструкторов, которые
     * создаются вручную (тесты), чтобы им не требовался ещё один аргумент.
     */
    public function __construct(
        private readonly LoggerInterface $performanceLogger = new NullLogger(),
        private readonly LoggerInterface $logger = new NullLogger(),
        #[Autowire('%app.performance_diagnostics_enabled%')]
        private readonly bool $enabled = false,
        #[Autowire('%app.performance_diagnostics_max_events_per_minute%')]
        private readonly int $maxEventsPerMinute = 1200,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function hasScope(): bool
    {
        return null !== $this->scope;
    }

    public function failedWrites(): int
    {
        return $this->failedWrites;
    }

    /**
     * Открывает область. Незакрытая предыдущая закрывается с outcome=unknown.
     */
    public function beginScope(
        string $job,
        string $provider = 'none',
        ?string $companyId = null,
        ?string $transport = null,
        ?string $trace = null,
        ?int $retryCount = null,
    ): void {
        if (!$this->enabled) {
            return;
        }

        try {
            if (null !== $this->scope) {
                $this->endScope(PerformanceOutcome::Unknown);
            }

            $this->spans = [];
            $this->scope = [
                'job' => $this->identifier($job) ?? 'other',
                'provider' => $this->provider($provider),
                'companyId' => null !== $companyId && 1 === preg_match(self::UUID_PATTERN, $companyId) ? strtolower($companyId) : null,
                'transport' => null !== $transport ? $this->identifier($transport) : null,
                'trace' => null !== $trace && 1 === preg_match(self::TRACE_PATTERN, $trace) ? $trace : null,
                'retryCount' => $retryCount,
                'startedAt' => hrtime(true),
                'memoryBase' => 0,
            ];

            // Пик памяти — на область, а не на процесс: воркер живёт час и иначе
            // каждое сообщение несло бы максимум всех предыдущих. Сброс опускает пик до
            // текущего потребления, поэтому пик включает базу процесса (ядро, контейнер);
            // прирост от сообщения — memory_peak_bytes − memory_base_bytes события handler.
            memory_reset_peak_usage();
            $this->scope['memoryBase'] = memory_get_usage(true);
        } catch (\Throwable $e) {
            $this->reportFailure($e);
        }
    }

    /**
     * Провайдер, известный только после загрузки документа (ProcessDayReport, step).
     */
    public function setProvider(string $provider): void
    {
        if (null === $this->scope) {
            return;
        }

        $this->scope['provider'] = $this->provider($provider);
    }

    /**
     * Закрывает область: по событию на накопленный этап и одно `handler`.
     */
    public function endScope(PerformanceOutcome $outcome): void
    {
        if (null === $this->scope) {
            return;
        }

        $scope = $this->scope;
        $spans = $this->spans;
        $this->scope = null;
        $this->spans = [];

        try {
            $memoryPeak = memory_get_peak_usage(true);

            foreach ($spans as $span) {
                $this->write($scope, [
                    'stage' => $span['stage']->value,
                    'provider' => $span['provider'],
                    'backend' => $span['backend'],
                    'duration_ms' => $this->ms($span['ns']),
                    'calls' => $span['calls'],
                    'rows' => $span['rows'],
                    'bytes' => $span['bytes'],
                    'errors' => $span['errors'],
                    'memory_peak_bytes' => $memoryPeak,
                    'outcome' => $outcome->value,
                ]);
            }

            $this->write($scope, [
                'stage' => PerformanceStage::Handler->value,
                'provider' => $scope['provider'],
                'duration_ms' => $this->ms(hrtime(true) - $scope['startedAt']),
                'calls' => 1,
                'errors' => PerformanceOutcome::Ok === $outcome ? 0 : 1,
                'memory_peak_bytes' => $memoryPeak,
                'memory_base_bytes' => $scope['memoryBase'],
                'outcome' => $outcome->value,
            ]);
        } catch (\Throwable $e) {
            $this->reportFailure($e);
        }
    }

    /**
     * Ожидание в очереди пишется сразу, без накопления.
     *
     * @param 'redis_stream_id'|'unavailable' $lagSource
     */
    public function recordQueueWait(?float $lagMs, string $lagSource): void
    {
        if (null === $this->scope) {
            return;
        }

        try {
            $this->write($this->scope, [
                'stage' => PerformanceStage::QueueWait->value,
                'provider' => $this->scope['provider'],
                'duration_ms' => null === $lagMs ? null : round(max(0.0, $lagMs), 3),
                'lag_source' => $lagSource,
                'calls' => 1,
                'outcome' => PerformanceOutcome::Ok->value,
            ]);
        } catch (\Throwable $e) {
            $this->reportFailure($e);
        }
    }

    /**
     * Метка начала этапа; null — замер не нужен (флаг выключен или вне области).
     */
    public function start(): ?int
    {
        if (!$this->enabled || null === $this->scope) {
            return null;
        }

        return hrtime(true);
    }

    public function add(
        PerformanceStage $stage,
        ?int $startedAt,
        ?int $rows = null,
        ?int $bytes = null,
        bool $failed = false,
        ?string $backend = null,
    ): void {
        if (null === $startedAt || null === $this->scope) {
            return;
        }

        $this->addElapsed($stage, hrtime(true) - $startedAt, 1, $rows, $bytes, $failed ? 1 : 0, $backend);
    }

    /**
     * Уже посчитанное время — для горячих циклов, где замер копится локальной
     * переменной и сдаётся одним вызовом (классификация строк).
     */
    public function addElapsed(
        PerformanceStage $stage,
        int $elapsedNs,
        int $calls = 1,
        ?int $rows = null,
        ?int $bytes = null,
        int $errors = 0,
        ?string $backend = null,
    ): void {
        if (null === $this->scope) {
            return;
        }

        $provider = $this->scope['provider'];
        $key = $stage->value.'|'.$provider.'|'.($backend ?? '');

        $span = $this->spans[$key] ?? [
            'stage' => $stage,
            'provider' => $provider,
            'backend' => $backend,
            'ns' => 0,
            'calls' => 0,
            'rows' => null,
            'bytes' => null,
            'errors' => 0,
        ];

        $span['ns'] += $elapsedNs;
        $span['calls'] += $calls;
        if (null !== $rows) {
            $span['rows'] = ($span['rows'] ?? 0) + $rows;
        }
        if (null !== $bytes) {
            $span['bytes'] = ($span['bytes'] ?? 0) + $bytes;
        }
        $span['errors'] += $errors;

        $this->spans[$key] = $span;
    }

    /**
     * Замер вызова. Исключение замеряемого кода пробрасывается без изменений,
     * этап при этом получает errors+1.
     *
     * @template T
     *
     * @param callable(PerformanceProbe): T $callback
     *
     * @return T
     */
    public function measure(PerformanceStage $stage, callable $callback, ?string $backend = null): mixed
    {
        $probe = new PerformanceProbe();
        $startedAt = $this->start();

        try {
            $result = $callback($probe);
        } catch (\Throwable $e) {
            $this->add($stage, $startedAt, $probe->rows, $probe->bytes, true, $backend);

            throw $e;
        }

        $this->add($stage, $startedAt, $probe->rows, $probe->bytes, false, $backend);

        return $result;
    }

    /**
     * @param array{job: string, provider: string, companyId: ?string, transport: ?string, trace: ?string, retryCount: ?int, startedAt: int, memoryBase: int} $scope
     * @param array<string, scalar|null> $fields
     */
    private function write(array $scope, array $fields): void
    {
        $now = hrtime(true);
        if ($now - $this->windowStartedAt >= self::WINDOW_NS) {
            $this->windowStartedAt = $now;
            $this->windowEvents = 0;
        }

        if ($this->windowEvents >= $this->maxEventsPerMinute) {
            ++$this->droppedEvents;

            return;
        }

        ++$this->windowEvents;

        $context = ['v' => self::SCHEMA_VERSION] + $fields + [
            'retry_count' => $scope['retryCount'],
            'company_id' => $scope['companyId'],
            'job' => $scope['job'],
            'transport' => $scope['transport'],
            'trace' => $scope['trace'],
        ];

        if ($this->droppedEvents > 0) {
            $context['dropped_events'] = $this->droppedEvents;
            $this->droppedEvents = 0;
        }

        try {
            $this->performanceLogger->info(self::LOG_MESSAGE, $context);
        } catch (\Throwable $e) {
            $this->reportFailure($e);
        }
    }

    /**
     * Диагностика не должна ронять бизнес-операцию. Сбой — WARNING (ожидаемо и
     * обрабатывается само), один раз на процесс, без сообщения исключения: в нём
     * может оказаться путь или фрагмент данных.
     */
    public function reportFailure(\Throwable $e): void
    {
        ++$this->failedWrites;

        if (1 !== $this->failedWrites) {
            return;
        }

        try {
            $this->logger->warning('Performance diagnostics write failed; further failures in this process are counted silently.', [
                'exception_class' => $e::class,
            ]);
        } catch (\Throwable) {
            // Основной лог тоже недоступен — последний канал: error_log процесса (stderr контейнера).
            error_log('Performance diagnostics write failed: '.$e::class);
        }
    }

    private function provider(string $provider): string
    {
        return \in_array($provider, self::PROVIDERS, true) ? $provider : 'none';
    }

    private function identifier(string $value): ?string
    {
        return 1 === preg_match(self::IDENTIFIER_PATTERN, $value) ? $value : null;
    }

    private function ms(int $ns): float
    {
        return round($ns / 1_000_000, 3);
    }
}
