<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Command;

use App\MarketplaceOzon\Application\Action\TranslateOzonDayAction;
use App\MarketplaceOzon\Domain\AccrualByDayTranslator;
use App\MarketplaceOzon\Infrastructure\Query\OzonTranslationStore;
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
 * Перевод начислений Ozon by-day в канон (cron каждые 15 минут, после `app:source-facts:pull`).
 *
 * День переводится, если у фактов новая ревизия, сменилась версия правил или по коду из его
 * карантина принято решение; с `--relink` — ещё и дни с SKU без листинга (раз в сутки).
 * Каждый день — своя транзакция; сбой дня не останавливает остальные, в конце один `error`.
 */
#[AsCommand(name: 'app:marketplace-ozon:translate', description: 'Translates Ozon by-day accrual facts into canonical marketplace operations')]
final class TranslateOzonAccrualsCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly SourceFactsFacadeInterface $sourceFacts,
        private readonly OzonTranslationStore $store,
        private readonly TranslateOzonDayAction $translate,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('relink', null, InputOption::VALUE_NONE, 'Also retranslate days with SKUs that had no listing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max days per run', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('marketplace-ozon translate: another run is in progress, skipping');

            return self::SUCCESS;
        }

        try {
            $states = $this->store->dayStates();
            $decided = $this->store->daysWithDecidedQuarantine();
            $relink = (bool) $input->getOption('relink');
            $limit = max(1, (int) $input->getOption('limit'));

            $due = [];
            foreach ($this->sourceFacts->periods(TranslateOzonDayAction::RESOURCE) as $period) {
                $key = OzonTranslationStore::dayKey($period->companyId, $period->connectionRef, $period->periodKey);
                $state = $states[$key] ?? null;
                if (null === $state
                    || $state['revision'] !== $period->revision
                    || AccrualByDayTranslator::RULES_VERSION !== $state['rules']
                    || isset($decided[$key])
                    || ($relink && $state['unresolved'] > 0)) {
                    $due[] = $period;
                }
            }

            $this->logger->info('Ozon accrual translation started.', ['due' => \count($due), 'limit' => $limit]);
            $done = $operations = 0;
            $failed = [];
            foreach (\array_slice($due, 0, $limit) as $period) {
                try {
                    $result = $this->connection->transactional(fn (): array => ($this->translate)($period));
                    ++$done;
                    $operations += $result['operations'];
                } catch (\Throwable $e) {
                    $failed[] = ['companyId' => $period->companyId, 'date' => $period->periodKey, 'error' => mb_substr($e->getMessage(), 0, 200)];
                }
            }

            if ([] !== $failed) {
                $this->logger->error('Ozon accrual translation: days failed.', ['count' => \count($failed), 'days' => \array_slice($failed, 0, 10)]);
            }
            $this->logger->info('Ozon accrual translation finished.', ['translated' => $done, 'operations' => $operations, 'failed' => \count($failed), 'left' => max(0, \count($due) - $limit)]);
            $output->writeln(sprintf('marketplace-ozon translate: %d due, %d translated (%d operations), %d failed', \count($due), $done, $operations, \count($failed)));

            return [] === $failed ? self::SUCCESS : self::FAILURE;
        } finally {
            $this->release();
        }
    }
}
