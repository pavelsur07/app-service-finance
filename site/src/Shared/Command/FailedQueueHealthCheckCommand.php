<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Infrastructure\Messenger\FailedTransportQuery;
use App\Shared\Messenger\FailedQueueHealthPolicy;
use App\Shared\Messenger\FailedQueueSnapshot;
use App\Shared\Messenger\FailedQueueVerdict;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only гейт: очередь неудавшихся сообщений Messenger (failure transport `failed`).
 *
 * Команда только читает (SELECT): ничего не повторяет, не удаляет и не изменяет. Что делать
 * с найденными сообщениями — решение человека (`messenger:failed:show|retry|remove`, мутации
 * только с разрешения Владельца, docs/maintenance/prod-access.md).
 *
 * Сигнал — один агрегированный `error` на запуск (не по сообщению) со стабильным текстом, чтобы
 * GlitchTip группировал повторы в одну проблему; детали (глубина, возраст, разбивка) — в контексте.
 * Запуск раз в сутки (docker/cron/app.cron): повторяющееся неизменное состояние даёт один
 * событие в день, а не поток. Недоступность хранилища — отдельный `error`, а не «очередь пуста».
 */
#[AsCommand(
    name: 'app:messenger:failed-queue-check',
    description: 'Read-only gate: глубина и возраст очереди failed (ничего не повторяет и не удаляет).',
)]
final class FailedQueueHealthCheckCommand extends Command
{
    public function __construct(
        private readonly FailedTransportQuery $query,
        private readonly FailedQueueHealthPolicy $policy,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('start');

        try {
            $snapshot = $this->query->snapshot();
        } catch (\Throwable $exception) {
            $this->logger->error('Messenger failed queue check unavailable', ['exception' => $exception]);
            $output->writeln(sprintf('<error>failed queue check UNAVAILABLE: %s</error>', OutputFormatter::escape($exception->getMessage())));

            return Command::FAILURE;
        }

        $assessment = $this->policy->evaluate($snapshot);

        $output->writeln(sprintf('failed queue: %s', strtoupper($assessment->verdict->value)));
        $output->writeln(sprintf('failed count: %d', $snapshot->count));
        if (!$snapshot->isEmpty()) {
            $output->writeln(sprintf(
                'oldest: %s (%s UTC)',
                FailedQueueHealthPolicy::formatAge((int) $snapshot->oldestAgeSeconds),
                $snapshot->oldestCreatedAt?->format('Y-m-d H:i:s'),
            ));
            foreach ($snapshot->breakdown as $row) {
                $output->writeln(sprintf('  %s [%s]: %d', $row['message_class'], $row['original_transport'], $row['count']));
            }
            if ([] !== $snapshot->breakdown) {
                $output->writeln(sprintf('breakdown coverage: %d of %d messages', $snapshot->breakdownSampled, $snapshot->count));
            }
        }
        $output->writeln('finish');

        if (FailedQueueVerdict::OK === $assessment->verdict) {
            return Command::SUCCESS;
        }

        $context = $this->context($snapshot, $assessment->reasons);

        if (FailedQueueVerdict::WARNING === $assessment->verdict) {
            $this->logger->warning('Messenger failed queue has recent messages', $context);

            return Command::SUCCESS;
        }

        $this->logger->error('Messenger failed queue unhealthy', $context);

        return Command::FAILURE;
    }

    /**
     * @param list<string> $reasons
     *
     * @return array<string, mixed>
     */
    private function context(FailedQueueSnapshot $snapshot, array $reasons): array
    {
        $breakdown = [];
        foreach ($snapshot->breakdown as $row) {
            $breakdown[sprintf('%s [%s]', $row['message_class'], $row['original_transport'])] = $row['count'];
        }

        return [
            'failed_count' => $snapshot->count,
            'oldest_age_seconds' => $snapshot->oldestAgeSeconds,
            'oldest_age' => FailedQueueHealthPolicy::formatAge((int) $snapshot->oldestAgeSeconds),
            'oldest_created_at_utc' => $snapshot->oldestCreatedAt?->format('Y-m-d H:i:s'),
            'reasons' => $reasons,
            'breakdown' => $breakdown,
            'breakdown_sampled' => $snapshot->breakdownSampled,
        ];
    }
}
