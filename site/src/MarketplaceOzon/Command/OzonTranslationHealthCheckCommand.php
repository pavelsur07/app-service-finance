<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Command;

use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
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
 * - error: день с фактами не переведён на текущей ревизии или версии правил — переводчик стоит;
 * - error: Σ канона дня (без справочных) ≠ Σ, отправленной переводчиком (`moz_day.canon_minor`), за
 *   последние WINDOW_DAYS — строки канона потеряны или чужие;
 * - error: последний день фактов кабинета старше LAG_DAYS — сервис застрял на хвосте.
 *
 * exit 1 — штатный результат гейта.
 */
#[AsCommand(name: 'app:marketplace-ozon:health-check', description: 'Ozon translator gate: pending source types, untranslated days')]
final class OzonTranslationHealthCheckCommand extends Command
{
    public const PENDING_HOURS = 24;
    /** Окно сверки = окно ночной перезагрузки фактов. */
    public const WINDOW_DAYS = 45;
    /** by-day приходит на D+1; утром последний день — вчера. */
    public const LAG_DAYS = 2;

    public function __construct(
        private readonly SourceFactsFacadeInterface $sourceFacts,
        private readonly OzonTranslationStore $store,
        private readonly MarketplaceOperationsFacadeInterface $operations,
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
        /** @var array<string, string> $lastDay кабинет → последний день фактов */
        $lastDay = [];
        foreach ($this->sourceFacts->periods(TranslateOzonDayAction::RESOURCE) as $period) {
            $cabinet = $period->companyId.'|'.$period->connectionRef;
            $lastDay[$cabinet] = max($lastDay[$cabinet] ?? '', $period->periodKey);
            $state = $states[OzonTranslationStore::dayKey($period->companyId, $period->connectionRef, $period->periodKey)] ?? null;
            if (null === $state || $state['revision'] !== $period->revision || AccrualByDayTranslator::RULES_VERSION !== $state['rules']) {
                $behind[$period->companyId] = ($behind[$period->companyId] ?? 0) + 1;
            }
        }
        if ([] !== $behind) {
            $this->logger->error('Ozon translation health: days are not translated.', ['count' => array_sum($behind), 'companyIds' => array_keys($behind)]);
        }

        $today = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);
        $mismatch = $this->canonMismatch($today->modify(sprintf('-%d days', self::WINDOW_DAYS)), $today);
        if ([] !== $mismatch) {
            $this->logger->error('Ozon translation health: canon differs from translated days.', [
                'count' => \count($mismatch),
                'diffRub' => array_sum(array_map('abs', $mismatch)) / 100,
                'days' => \array_slice(array_keys($mismatch), 0, 10),
            ]);
        }

        $threshold = $today->modify(sprintf('-%d days', self::LAG_DAYS))->format('Y-m-d');
        $lagging = array_filter($lastDay, static fn (string $day): bool => $day < $threshold);
        if ([] !== $lagging) {
            $this->logger->error('Ozon translation health: cabinets lag behind.', ['count' => \count($lagging), 'lastDays' => $lagging]);
        }

        $output->writeln(sprintf('marketplace-ozon health: %d pending source types, %d untranslated days, %d canon mismatches, %d lagging cabinets', \count($pending), array_sum($behind), \count($mismatch), \count($lagging)));

        return [] === $pending && [] === $behind && [] === $mismatch && [] === $lagging ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, int> dayKey → канон − перевод, только ненулевые */
    private function canonMismatch(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $diff = array_map(static fn (int $minor): int => -$minor, $this->store->canonTotals($from, $to));
        foreach ($this->operations->daySums(TranslateOzonDayAction::CHANNEL, $from, $to) as $sum) {
            $key = OzonTranslationStore::dayKey($sum->companyId, $sum->connectionRef, $sum->date);
            $diff[$key] = ($diff[$key] ?? 0) + $sum->amountMinor;
        }

        return array_filter($diff, static fn (int $d): bool => 0 !== $d);
    }
}
