<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Command;

use App\MarketplaceFunnel\Domain\FunnelRow;
use App\MarketplaceFunnel\Domain\OzonFunnelTranslator;
use App\MarketplaceFunnel\Domain\WbFunnelTranslator;
use App\MarketplaceFunnel\Infrastructure\Query\FunnelStore;
use App\SourceFacts\Application\DTO\SourcePeriod;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Перевод фактов воронки Ozon и WB в таблицу `mfn_row` (cron каждые 15 минут, после `app:source-facts:pull`).
 *
 * День переводится, если у его фактов новая ревизия или сменилась версия правил. День — одна транзакция
 * и замена целиком; запись другой формы — день не переводится (в конце один `error`), остальные идут.
 */
#[AsCommand(name: 'app:marketplace-funnel:translate', description: 'Translates Ozon and WB funnel facts into the daily funnel table')]
final class TranslateFunnelCommand extends Command
{
    use LockableTrait;

    /** ресурс фактов → канал */
    public const RESOURCES = [OzonFunnelTranslator::RESOURCE => OzonFunnelTranslator::CHANNEL, WbFunnelTranslator::RESOURCE => WbFunnelTranslator::CHANNEL];

    public function __construct(
        private readonly SourceFactsFacadeInterface $sourceFacts,
        private readonly FunnelStore $store,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max days per run', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('marketplace-funnel translate: another run is in progress, skipping');

            return self::SUCCESS;
        }

        try {
            $states = $this->store->dayStates();
            $due = [];
            foreach (array_keys(self::RESOURCES) as $resource) {
                foreach ($this->sourceFacts->periods($resource) as $period) {
                    $state = $states[FunnelStore::dayKey($period->companyId, $period->connectionRef, $period->periodKey)] ?? null;
                    if (null === $state || $state['revision'] !== $period->revision || FunnelRow::RULES_VERSION !== $state['rules']) {
                        $due[] = [$resource, $period];
                    }
                }
            }

            $limit = max(1, (int) $input->getOption('limit'));
            $done = $rows = 0;
            $failed = [];
            foreach (\array_slice($due, 0, $limit) as [$resource, $period]) {
                try {
                    $rows += $this->connection->transactional(fn (): int => $this->translateDay($resource, $period));
                    ++$done;
                } catch (\Throwable $e) {
                    $failed[] = ['companyId' => $period->companyId, 'resource' => $resource, 'date' => $period->periodKey, 'error' => mb_substr($e->getMessage(), 0, 200)];
                }
            }

            if ([] !== $failed) {
                $this->logger->error('Funnel translation: days failed.', ['count' => \count($failed), 'days' => \array_slice($failed, 0, 10)]);
            }
            $this->logger->info('Funnel translation finished.', ['due' => \count($due), 'translated' => $done, 'rows' => $rows, 'failed' => \count($failed), 'left' => max(0, \count($due) - $limit)]);
            $output->writeln(sprintf('marketplace-funnel translate: %d due, %d translated (%d rows), %d failed', \count($due), $done, $rows, \count($failed)));

            return [] === $failed ? self::SUCCESS : self::FAILURE;
        } finally {
            $this->release();
        }
    }

    private function translateDay(string $resource, SourcePeriod $period): int
    {
        $rows = [];
        $available = true;
        foreach ($this->sourceFacts->activeFacts($period->companyId, $period->connectionRef, $resource, $period->periodKey) as $fact) {
            if (OzonFunnelTranslator::UNAVAILABLE_KEY === $fact->sourceKey) {
                $available = false;
                continue;
            }
            $rows[] = OzonFunnelTranslator::RESOURCE === $resource ? OzonFunnelTranslator::translate($fact->payload) : WbFunnelTranslator::translate($fact->payload);
        }
        $this->store->replaceDay($period->companyId, $period->connectionRef, self::RESOURCES[$resource], $period->periodKey, $period->revision, $available, $rows);

        return \count($rows);
    }
}
