<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Command;

use App\MarketplaceFunnel\Domain\FunnelRow;
use App\MarketplaceFunnel\Infrastructure\Query\FunnelStore;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Гейт таблицы воронки (cron 09:35 МСК):
 * - error: день с фактами не переведён на текущей ревизии или версии правил — переводчик стоит либо
 *   запись источника другой формы;
 * - error: строки `mfn_row` дня расходятся с переведённым (число строк, контрольная сумма) — строки
 *   потеряны или чужие.
 *
 * exit 1 — штатный результат гейта.
 */
#[AsCommand(name: 'app:marketplace-funnel:health-check', description: 'Funnel table gate: untranslated days, rows differ from translated')]
final class FunnelHealthCheckCommand extends Command
{
    public function __construct(
        private readonly SourceFactsFacadeInterface $sourceFacts,
        private readonly FunnelStore $store,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $states = $this->store->dayStates();
        $behind = [];
        foreach (array_keys(TranslateFunnelCommand::RESOURCES) as $resource) {
            foreach ($this->sourceFacts->periods($resource) as $period) {
                $state = $states[FunnelStore::dayKey($period->companyId, $period->connectionRef, $period->periodKey)] ?? null;
                if (null === $state || $state['revision'] !== $period->revision || FunnelRow::RULES_VERSION !== $state['rules']) {
                    $behind[$period->companyId] = ($behind[$period->companyId] ?? 0) + 1;
                }
            }
        }
        if ([] !== $behind) {
            $this->logger->error('Funnel health: days are not translated.', ['count' => array_sum($behind), 'companyIds' => array_keys($behind)]);
        }

        $mismatch = $this->store->mismatchedDays();
        if ([] !== $mismatch) {
            $this->logger->error('Funnel health: table rows differ from translated days.', ['count' => \count($mismatch), 'days' => \array_slice($mismatch, 0, 10)]);
        }

        $output->writeln(sprintf('marketplace-funnel health: %d untranslated days, %d mismatched days', array_sum($behind), \count($mismatch)));

        return [] === $behind && [] === $mismatch ? self::SUCCESS : self::FAILURE;
    }
}
