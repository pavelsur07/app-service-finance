<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Command;

use App\MarketplaceOzon\Application\Action\TranslateOzonDayAction;
use App\MarketplaceOzon\Domain\AccrualByDayTranslator;
use App\MarketplaceOzon\Infrastructure\Query\OzonTranslationStore;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Гейт переводчика Ozon (cron раз в сутки, docs/workflow/health-gates.md). Только чтение.
 *
 * - error: код без решения лежит в карантине дольше PENDING_HOURS — нужен администратор;
 * - error: день с фактами не переведён на текущей ревизии или версии правил — переводчик стоит.
 *
 * exit 1 — штатный результат гейта.
 */
#[AsCommand(name: 'app:marketplace-ozon:health-check', description: 'Ozon translator gate: pending source types, untranslated days')]
final class OzonTranslationHealthCheckCommand extends Command
{
    public const PENDING_HOURS = 24;

    public function __construct(
        private readonly SourceFactsFacadeInterface $sourceFacts,
        private readonly OzonTranslationStore $store,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pending = $this->store->stalePending($this->clock->now()->modify(sprintf('-%d hours', self::PENDING_HOURS)));
        if ([] !== $pending) {
            $this->logger->error('Ozon translation health: source types wait for a decision.', [
                'count' => \count($pending),
                'sourceTypes' => array_column($pending, 'source_type'),
            ]);
        }

        $states = $this->store->dayStates();
        $behind = [];
        foreach ($this->sourceFacts->periods(TranslateOzonDayAction::RESOURCE) as $period) {
            $state = $states[OzonTranslationStore::dayKey($period->companyId, $period->connectionRef, $period->periodKey)] ?? null;
            if (null === $state || $state['revision'] !== $period->revision || AccrualByDayTranslator::RULES_VERSION !== $state['rules']) {
                $behind[$period->companyId] = ($behind[$period->companyId] ?? 0) + 1;
            }
        }
        if ([] !== $behind) {
            $this->logger->error('Ozon translation health: days are not translated.', ['count' => array_sum($behind), 'companyIds' => array_keys($behind)]);
        }

        $output->writeln(sprintf('marketplace-ozon health: %d pending source types, %d untranslated days', \count($pending), array_sum($behind)));

        return [] === $pending && [] === $behind ? self::SUCCESS : self::FAILURE;
    }
}
