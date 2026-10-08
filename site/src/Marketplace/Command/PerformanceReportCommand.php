<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Shared\Infrastructure\Messenger\FailedTransportQuery;
use App\Shared\Infrastructure\Messenger\MessengerQueueSnapshotQuery;
use App\Shared\Infrastructure\Performance\PerformanceLogReader;
use App\Shared\Infrastructure\Performance\PerformanceRecorder;
use App\Shared\Infrastructure\Performance\PerformanceReportBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Отчёт M1 по диагностическим логам производительности (docs/architecture/marketplace-audit/09-m1-diagnostics.md).
 *
 * Только чтение: суточные файлы `var/log/performance-*.jsonl` за период (потолок байт и
 * событий), плюс по умолчанию живой снимок очередей — Redis O(1)-командами и агрегатный
 * SELECT по failed (тот же, что у app:messenger:failed-queue-check). Ничего не пишет,
 * не повторяет и не удаляет. Тяжёлых запросов к PostgreSQL нет.
 */
#[AsCommand(
    name: 'app:marketplace:perf-report',
    description: 'Read-only: отчёт по диагностике производительности Marketplace M1 (p50/p95/p99 по этапам, очередь, обработчики).',
)]
final class PerformanceReportCommand extends Command
{
    private const MAX_PERIOD_DAYS = 31;
    private const DEFAULT_MAX_MB = 256;
    private const DEFAULT_MAX_EVENTS = 1_000_000;

    public function __construct(
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logDir,
        private readonly MessengerQueueSnapshotQuery $queueSnapshot,
        private readonly FailedTransportQuery $failedQuery,
        private readonly PerformanceRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Первый день периода, YYYY-MM-DD (по умолчанию: 6 дней назад)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Последний день периода, YYYY-MM-DD (по умолчанию: сегодня)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'md | json', 'md')
            ->addOption('max-mb', null, InputOption::VALUE_REQUIRED, 'Потолок прочитанных мегабайт лога', (string) self::DEFAULT_MAX_MB)
            ->addOption('max-events', null, InputOption::VALUE_REQUIRED, 'Потолок событий', (string) self::DEFAULT_MAX_EVENTS)
            ->addOption('skip-queues', null, InputOption::VALUE_NONE, 'Не снимать живое состояние очередей (Redis, failed)')
            ->addOption('log-dir', null, InputOption::VALUE_REQUIRED, 'Каталог логов (по умолчанию kernel.logs_dir)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $today = new \DateTimeImmutable('today');
            $to = $this->date($input->getOption('to'), $today);
            $from = $this->date($input->getOption('from'), $to->modify('-6 days'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::INVALID;
        }

        $days = (int) $from->diff($to)->format('%r%a') + 1;
        if ($days < 1 || $days > self::MAX_PERIOD_DAYS) {
            $output->writeln(sprintf('<error>Период должен быть от 1 до %d дней, from <= to.</error>', self::MAX_PERIOD_DAYS));

            return Command::INVALID;
        }

        $format = (string) $input->getOption('format');
        if (!\in_array($format, ['md', 'json'], true)) {
            $output->writeln('<error>--format: md или json.</error>');

            return Command::INVALID;
        }

        $logDir = \is_string($input->getOption('log-dir')) ? $input->getOption('log-dir') : $this->logDir;
        $reader = new PerformanceLogReader(
            $logDir,
            max(1, (int) $input->getOption('max-mb')) * 1024 * 1024,
            max(1, (int) $input->getOption('max-events')),
        );

        $builder = new PerformanceReportBuilder();
        foreach ($reader->read($from, $to) as $event) {
            $builder->add($event);
        }

        $report = [
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'days' => $days],
            'diagnostics_enabled_in_this_process' => $this->recorder->isEnabled(),
            'source' => [
                'files_read' => $reader->filesRead,
                'files_missing' => $reader->filesMissing,
                'bytes_read' => $reader->bytesRead,
                'lines_read' => $reader->linesRead,
                'invalid_lines' => $reader->invalidLines,
                'foreign_lines' => $reader->foreignLines,
                'truncated' => $reader->truncated,
            ],
        ] + $builder->build();

        if (!$input->getOption('skip-queues')) {
            $report['queue_snapshot'] = $this->queueSnapshot();
        }

        if ('json' === $format) {
            $output->writeln((string) json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderMarkdown($report, $output);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function queueSnapshot(): array
    {
        $snapshot = [];

        try {
            $snapshot['redis'] = $this->queueSnapshot->snapshot();
        } catch (\Throwable $e) {
            $snapshot['redis'] = 'unavailable: '.$e::class;
        }

        try {
            $failed = $this->failedQuery->snapshot();
            $snapshot['failed'] = [
                'count' => $failed->count,
                'oldest_age_seconds' => $failed->oldestAgeSeconds,
                'breakdown' => $failed->breakdown,
            ];
        } catch (\Throwable $e) {
            $snapshot['failed'] = 'unavailable: '.$e::class;
        }

        return $snapshot;
    }

    private function date(mixed $value, \DateTimeImmutable $default): \DateTimeImmutable
    {
        if (null === $value) {
            return $default;
        }

        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('Дата "%s" не в формате YYYY-MM-DD.', \is_scalar($value) ? (string) $value : ''));
        }

        return $date;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderMarkdown(array $report, OutputInterface $output): void
    {
        $lines = [];
        $lines[] = sprintf('# Marketplace M1 performance report: %s … %s', $report['period']['from'], $report['period']['to']);
        $lines[] = '';
        $source = $report['source'];
        $lines[] = sprintf(
            'Источник: файлов %d (нет за %d дн.), %s прочитано, событий %d, битых строк %d, чужих %d%s.',
            $source['files_read'],
            $source['files_missing'],
            self::bytes($source['bytes_read']),
            $report['events'],
            $source['invalid_lines'],
            $source['foreign_lines'],
            $source['truncated'] ? ', **ОБРЕЗАНО по лимиту — отчёт неполный**' : '',
        );
        $lines[] = sprintf('Флаг диагностики в процессе отчёта: %s (флаг воркеров может отличаться).', $report['diagnostics_enabled_in_this_process'] ? 'включён' : 'выключен');

        if (0 === $report['events']) {
            $lines[] = '';
            $lines[] = '**Нет данных за период.** Проверить: MARKETPLACE_PERF_DIAGNOSTICS=1 у воркеров, их перезапуск после смены флага, наличие var/log/performance-*.jsonl и права записи.';
        }

        $lines[] = '';
        $lines[] = '## Этапы';
        $lines[] = '';
        $lines[] = 'Событие этапа — сумма времени этапа внутри одного сообщения/запуска. `financial_mapping`, `financial_posting` и `source_normalize` (Ozon) вложены в `processor_total`.';
        $lines[] = '';
        $lines[] = '| stage | provider | backend | events | calls | p50 ms | p95 ms | p99 ms | max ms | rows | rows/s | bytes | bytes/s | errors | max peak mem | rows n/a |';
        $lines[] = '|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|';
        foreach ($report['stages'] as $row) {
            $d = $row['duration_ms'];
            $lines[] = sprintf(
                '| %s | %s | %s | %d | %d | %s | %s | %s | %s | %s | %s | %s | %s | %d | %s | %d |',
                $row['stage'], $row['provider'], $row['backend'] ?? '—', $row['events'], $row['calls'],
                self::num($d['p50']), self::num($d['p95']), self::num($d['p99']), self::num($d['max']),
                self::num($row['rows']), self::num($row['rows_per_sec']), self::bytes($row['bytes']), self::bytes($row['bytes_per_sec']),
                $row['errors'], self::bytes($row['max_memory_peak_bytes']), $row['rows_missing_events'],
            );
        }

        $lines[] = '';
        $lines[] = '## Очередь: ожидание до старта обработки';
        $lines[] = '';
        $lines[] = '| transport | messages | p50 ms | p95 ms | p99 ms | max ms | lag n/a | redelivered |';
        $lines[] = '|---|---:|---:|---:|---:|---:|---:|---:|';
        foreach ($report['queues'] as $row) {
            $d = $row['lag_ms'];
            $lines[] = sprintf('| %s | %d | %s | %s | %s | %s | %d | %d |', $row['transport'], $row['messages'], self::num($d['p50']), self::num($d['p95']), self::num($d['p99']), self::num($d['max']), $row['lag_unavailable'], $row['redelivered']);
        }

        $lines[] = '';
        $lines[] = '## Обработчики';
        $lines[] = '';
        $lines[] = 'max peak mem включает базу процесса; max growth — прирост памяти за сообщение.';
        $lines[] = '';
        $lines[] = '| job | provider | messages | p50 ms | p95 ms | p99 ms | max ms | failed | retried | unknown | max peak mem | max growth |';
        $lines[] = '|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|';
        foreach ($report['handlers'] as $row) {
            $d = $row['duration_ms'];
            $lines[] = sprintf('| %s | %s | %d | %s | %s | %s | %s | %d | %d | %d | %s | %s |', $row['job'], $row['provider'], $row['messages'], self::num($d['p50']), self::num($d['p95']), self::num($d['p99']), self::num($d['max']), $row['failed'], $row['retried'], $row['unknown_outcome'], self::bytes($row['max_memory_peak_bytes']), self::bytes($row['max_memory_growth_bytes']));
        }

        $c = $report['completeness'];
        $lines[] = '';
        $lines[] = '## Полнота замеров';
        $lines[] = '';
        $lines[] = sprintf('- сообщений без события завершения (убит воркер, лимит, граница периода): %d', $c['messages_without_handler_event']);
        $lines[] = sprintf('- событий завершения без queue_wait (граница периода): %d', $c['handler_events_without_queue_wait']);
        $lines[] = sprintf('- событий без длительности (lag недоступен): %d', $c['events_without_duration']);
        $lines[] = sprintf('- отброшено лимитом событий в минуту: %d', $c['dropped_events']);

        if (isset($report['queue_snapshot'])) {
            $lines[] = '';
            $lines[] = '## Очереди сейчас';
            $lines[] = '';
            $redis = $report['queue_snapshot']['redis'];
            if (\is_array($redis)) {
                $lines[] = '| stream | transports | status | length | in progress | delayed | oldest age s |';
                $lines[] = '|---|---|---|---:|---:|---:|---:|';
                foreach ($redis as $row) {
                    $lines[] = sprintf('| %s | %s | %s | %s | %s | %s | %s |', $row['stream'], implode(', ', $row['transports']), $row['status'], self::num($row['length']), self::num($row['pending']), self::num($row['delayed']), self::num($row['oldest_age_seconds']));
                }
            } else {
                $lines[] = 'Redis: '.$redis;
            }
            $failed = $report['queue_snapshot']['failed'];
            $lines[] = '';
            $lines[] = \is_array($failed)
                ? sprintf('failed: %d, старейшее %s с', $failed['count'], self::num($failed['oldest_age_seconds']))
                : 'failed: '.$failed;
        }

        $output->writeln($lines);
    }

    private static function num(int|float|null $value): string
    {
        if (null === $value) {
            return 'n/a';
        }

        return \is_float($value) ? number_format($value, 1, '.', '') : (string) $value;
    }

    private static function bytes(int|float|null $value): string
    {
        if (null === $value) {
            return 'n/a';
        }

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if (abs($value) < 1024 || 'GB' === $unit) {
                return 'B' === $unit ? sprintf('%d B', $value) : sprintf('%.1f %s', $value, $unit);
            }
            $value /= 1024;
        }

        return (string) $value;
    }
}
