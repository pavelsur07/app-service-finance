<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Command;

use App\MarketplaceWb\Application\Action\TranslateWbDayAction;
use App\MarketplaceWb\Domain\SalesReportTranslator;
use App\MarketplaceWb\Infrastructure\Query\WbTranslationStore;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Перевод дневного отчёта WB в канон (cron каждые 15 минут, после `app:source-facts:pull`).
 *
 * День переводится, если у фактов новая ревизия, сменилась версия правил или по коду из его
 * карантина принято решение; с `--relink` — ещё и дни с SKU без листинга (раз в сутки).
 * Каждый день — своя транзакция; сбой дня не останавливает остальные, в конце один `error`.
 * Включение — `MARKETPLACE_WB_COMPANIES` (UUID через запятую, * — все, пусто — выключено).
 */
#[AsCommand(name: 'app:marketplace-wb:translate', description: 'Translates WB daily sales report facts into canonical marketplace operations')]
final class TranslateWbSalesReportCommand extends Command
{
    use LockableTrait;

    public const ALL = '*';

    /** @var list<string> */
    private readonly array $allowed;

    public function __construct(
        private readonly SourceFactsFacadeInterface $sourceFacts,
        private readonly WbTranslationStore $store,
        private readonly TranslateWbDayAction $translate,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::MARKETPLACE_WB_COMPANIES)%')]
        ?string $allowed = null,
    ) {
        parent::__construct();
        $this->allowed = array_values(array_filter(array_map(static fn (string $id): string => strtolower(trim($id)), explode(',', (string) $allowed))));
    }

    protected function configure(): void
    {
        $this
            ->addOption('relink', null, InputOption::VALUE_NONE, 'Also retranslate days with SKUs that had no listing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max days per run', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ([] === $this->allowed) {
            $output->writeln('marketplace-wb translate: выключено (MARKETPLACE_WB_COMPANIES пуст)');

            return self::SUCCESS;
        }
        if (!$this->lock()) {
            $output->writeln('marketplace-wb translate: another run is in progress, skipping');

            return self::SUCCESS;
        }

        try {
            $states = $this->store->dayStates();
            $decided = $this->store->daysWithDecidedQuarantine();
            $relink = (bool) $input->getOption('relink');
            $limit = max(1, (int) $input->getOption('limit'));
            $all = \in_array(self::ALL, $this->allowed, true);

            $due = [];
            foreach ($this->sourceFacts->periods(TranslateWbDayAction::RESOURCE) as $period) {
                if (!$all && !\in_array($period->companyId, $this->allowed, true)) {
                    continue;
                }
                $key = WbTranslationStore::dayKey($period->companyId, $period->connectionRef, $period->periodKey);
                $state = $states[$key] ?? null;
                if (null === $state
                    || $state['revision'] !== $period->revision
                    || SalesReportTranslator::RULES_VERSION !== $state['rules']
                    || isset($decided[$key])
                    || ($relink && $state['unresolved'] > 0)) {
                    $due[] = $period;
                }
            }

            $this->logger->info('WB sales report translation started.', ['due' => \count($due), 'limit' => $limit]);
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
                $this->logger->error('WB sales report translation: days failed.', ['count' => \count($failed), 'days' => \array_slice($failed, 0, 10)]);
            }
            $this->logger->info('WB sales report translation finished.', ['translated' => $done, 'operations' => $operations, 'failed' => \count($failed), 'left' => max(0, \count($due) - $limit)]);
            $output->writeln(sprintf('marketplace-wb translate: %d due, %d translated (%d operations), %d failed', \count($due), $done, $operations, \count($failed)));

            return [] === $failed ? self::SUCCESS : self::FAILURE;
        } finally {
            $this->release();
        }
    }
}
