<?php

declare(strict_types=1);

namespace App\SourceFacts\Command;

use App\SourceFacts\Application\Action\PullSourceFactsAction;
use App\SourceFacts\Infrastructure\Query\SourceFactStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Гейт приёма фактов (cron раз в сутки, docs/workflow/health-gates.md). Только чтение.
 *
 * - error: есть пачки в карантине (сервису не подтверждены, приходят снова);
 * - error: в поток (кабинет × ресурс), который уже приходил, ничего не применено дольше STALE_HOURS.
 *
 * Граница: поток, который не пришёл ни разу, гейт не видит — это покрытие сервиса
 * (`app:service-gateway:coverage:refresh`). Приём выключен — exit 0. exit 1 — штатный результат гейта.
 */
#[AsCommand(name: 'app:source-facts:health-check', description: 'Source facts gate: quarantined batches, stale streams')]
final class SourceFactsHealthCheckCommand extends Command
{
    public const STALE_HOURS = 36;

    public function __construct(
        private readonly PullSourceFactsAction $pull,
        private readonly SourceFactStore $store,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->pull->isEnabled()) {
            $output->writeln('source-facts health: disabled');

            return self::SUCCESS;
        }

        $health = $this->store->health($this->clock->now()->modify(sprintf('-%d hours', self::STALE_HOURS)));
        if ($health['quarantined'] > 0) {
            $this->logger->error('Source facts health: batches are quarantined.', [
                'count' => $health['quarantined'],
                'reasons' => $health['quarantineReasons'],
            ]);
        }
        if ([] !== $health['staleStreams']) {
            $this->logger->error('Source facts health: streams without applied batches.', [
                'count' => \count($health['staleStreams']),
                'staleHours' => self::STALE_HOURS,
                'companyIds' => array_values(array_unique(array_column($health['staleStreams'], 'company_id'))),
            ]);
        }
        $output->writeln(sprintf('source-facts health: %d quarantined, %d stale streams', $health['quarantined'], \count($health['staleStreams'])));

        return 0 === $health['quarantined'] && [] === $health['staleStreams'] ? self::SUCCESS : self::FAILURE;
    }
}
