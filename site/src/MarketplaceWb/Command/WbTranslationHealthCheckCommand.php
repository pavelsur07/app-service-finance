<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Command;

use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\MarketplaceWb\Application\Action\TranslateWbDayAction;
use App\MarketplaceWb\Domain\SalesReportTranslator;
use App\MarketplaceWb\Infrastructure\Query\WbTranslationStore;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Гейт переводчика WB (cron раз в сутки, docs/workflow/health-gates.md). Только чтение.
 *
 * - error: код без решения лежит в карантине дольше PENDING_HOURS — нужен администратор;
 * - error: день с фактами не переведён на текущей ревизии или версии правил — переводчик стоит;
 * - error: Σ канона дня (без справочных) ≠ Σ, отправленной переводчиком (`mwb_day.canon_minor`), за
 *   последние WINDOW_DAYS — строки канона потеряны или чужие;
 * - error: последний день фактов кабинета старше LAG_DAYS — сервис застрял на хвосте.
 *
 * Смотрит только компании из `MARKETPLACE_WB_COMPANIES` (пусто — гейт молчит, exit 0).
 *
 * exit 1 — штатный результат гейта.
 */
#[AsCommand(name: 'app:marketplace-wb:health-check', description: 'WB translator gate: pending source types, untranslated days')]
final class WbTranslationHealthCheckCommand extends Command
{
    public const PENDING_HOURS = 24;
    /** Окно сверки = окно ночной перезагрузки фактов. */
    public const WINDOW_DAYS = 45;
    /** отчёт дня приходит на D+1; утром последний день — вчера. */
    public const LAG_DAYS = 2;

    public function __construct(
        private readonly SourceFactsFacadeInterface $sourceFacts,
        private readonly WbTranslationStore $store,
        private readonly MarketplaceOperationsFacadeInterface $operations,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::MARKETPLACE_WB_COMPANIES)%')]
        ?string $allowed = null,
    ) {
        parent::__construct();
        $this->allowed = array_values(array_filter(array_map(static fn (string $id): string => strtolower(trim($id)), explode(',', (string) $allowed))));
    }

    /** @var list<string> */
    private readonly array $allowed;

    private function enabled(string $companyId): bool
    {
        return \in_array(TranslateWbSalesReportCommand::ALL, $this->allowed, true) || \in_array($companyId, $this->allowed, true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ([] === $this->allowed) {
            $output->writeln('marketplace-wb health: выключено (MARKETPLACE_WB_COMPANIES пуст)');

            return self::SUCCESS;
        }
        $pending = $this->store->stalePending($this->clock->now()->modify(sprintf('-%d hours', self::PENDING_HOURS)));
        if ([] !== $pending) {
            $this->logger->error('WB translation health: source types wait for a decision.', [
                'count' => \count($pending),
                'sourceTypes' => array_column($pending, 'source_type'),
            ]);
        }

        $states = $this->store->dayStates();
        $behind = [];
        /** @var array<string, string> $lastDay кабинет → последний день фактов */
        $lastDay = [];
        foreach ($this->sourceFacts->periods(TranslateWbDayAction::RESOURCE) as $period) {
            if (!$this->enabled($period->companyId)) {
                continue;
            }
            $cabinet = $period->companyId.'|'.$period->connectionRef;
            $lastDay[$cabinet] = max($lastDay[$cabinet] ?? '', $period->periodKey);
            $state = $states[WbTranslationStore::dayKey($period->companyId, $period->connectionRef, $period->periodKey)] ?? null;
            if (null === $state || $state['revision'] !== $period->revision || SalesReportTranslator::RULES_VERSION !== $state['rules']) {
                $behind[$period->companyId] = ($behind[$period->companyId] ?? 0) + 1;
            }
        }
        if ([] !== $behind) {
            $this->logger->error('WB translation health: days are not translated.', ['count' => array_sum($behind), 'companyIds' => array_keys($behind)]);
        }

        $today = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);
        $mismatch = $this->canonMismatch($today->modify(sprintf('-%d days', self::WINDOW_DAYS)), $today);
        if ([] !== $mismatch) {
            $this->logger->error('WB translation health: canon differs from translated days.', [
                'count' => \count($mismatch),
                'diffRub' => array_sum(array_map('abs', $mismatch)) / 100,
                'days' => \array_slice(array_keys($mismatch), 0, 10),
            ]);
        }

        $threshold = $today->modify(sprintf('-%d days', self::LAG_DAYS))->format('Y-m-d');
        $lagging = array_filter($lastDay, static fn (string $day): bool => $day < $threshold);
        if ([] !== $lagging) {
            $this->logger->error('WB translation health: cabinets lag behind.', ['count' => \count($lagging), 'lastDays' => $lagging]);
        }

        $output->writeln(sprintf('marketplace-wb health: %d pending source types, %d untranslated days, %d canon mismatches, %d lagging cabinets', \count($pending), array_sum($behind), \count($mismatch), \count($lagging)));

        return [] === $pending && [] === $behind && [] === $mismatch && [] === $lagging ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, int> dayKey → канон − перевод, только ненулевые */
    private function canonMismatch(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $diff = array_map(static fn (int $minor): int => -$minor, $this->store->canonTotals($from, $to));
        foreach ($this->operations->daySums(TranslateWbDayAction::CHANNEL, $from, $to) as $sum) {
            if (!$this->enabled($sum->companyId)) {
                continue;
            }
            $key = WbTranslationStore::dayKey($sum->companyId, $sum->connectionRef, $sum->date);
            $diff[$key] = ($diff[$key] ?? 0) + $sum->amountMinor;
        }

        return array_filter($diff, static fn (int $d): bool => 0 !== $d);
    }
}
