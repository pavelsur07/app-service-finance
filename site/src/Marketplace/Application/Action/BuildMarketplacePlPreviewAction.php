<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Finance\Application\DTO\PlPreviewColumn;
use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Application\Command\BuildMarketplacePlPreviewCommand;
use App\Marketplace\Application\DTO\MarketplacePlPreviewColumn;
use App\Marketplace\Application\DTO\MarketplacePlPreviewReport;
use App\Marketplace\Application\DTO\PlPreviewAdLedgerDTO;
use App\Marketplace\Application\DTO\PlPreviewAdReconciliationDTO;
use App\Marketplace\Application\DTO\PlPreviewCostCoverageDTO;
use App\Marketplace\Application\DTO\PlPreviewFactDTO;
use App\Marketplace\Application\DTO\PlPreviewSalesCoverageDTO;
use App\Marketplace\DTO\ListingTagDTO;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewColumnKind;
use App\Marketplace\Exception\MarketplacePlPreviewPeriodException;
use App\Marketplace\Facade\PlPreviewAdSpendProviderInterface;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Marketplace\Infrastructure\Query\OzonPlMappingWarningsQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewAdLedgerQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewCostCoverageQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewFactsQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewSalesCoverageQuery;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceListingTagRepository;

/**
 * Предпросмотр ОПиУ по тегам: что покажет ОПиУ, если провести нормализованные данные периода по
 * текущему маппингу. Только чтение — ни документов ОПиУ, ни регистра, ни отметок у строк.
 *
 * Колонки: теги (полная сумма листинга в каждый его тег), «Общие / без тега» (без листинга или
 * листинг без тегов) и «Итого» (отдельный расчёт без разреза по листингам — совпадает с
 * проведением, см. PlPreviewFactsQuery).
 */
final readonly class BuildMarketplacePlPreviewAction
{
    public const int MAX_PERIOD_DAYS = 366;
    public const string COMMON_KEY = 'common';
    public const string TOTAL_KEY = 'total';
    private const int LISTING_CHUNK = 5000;

    public function __construct(
        private PlPreviewFactsQuery $factsQuery,
        private PlPreviewCostCoverageQuery $costCoverageQuery,
        private PlPreviewSalesCoverageQuery $salesCoverageQuery,
        private MarketplaceListingTagRepository $tagRepository,
        private ListingTagAssignmentRepository $tagAssignments,
        private MarketplaceConnectionRepository $connections,
        private FinanceFacade $financeFacade,
        private PlPreviewAdLedgerQuery $adLedgerQuery,
        private PlPreviewAdSpendProviderInterface $adSpend,
        private OzonPlMappingWarningsQuery $mappingWarnings,
    ) {
    }

    public function __invoke(BuildMarketplacePlPreviewCommand $command): MarketplacePlPreviewReport
    {
        $from = $command->from->setTime(0, 0);
        $to = $command->to->setTime(0, 0);
        if ($from > $to) {
            throw MarketplacePlPreviewPeriodException::reversed();
        }
        if ((int) $from->diff($to)->days + 1 > self::MAX_PERIOD_DAYS) {
            throw MarketplacePlPreviewPeriodException::tooLong(self::MAX_PERIOD_DAYS);
        }

        $companyId = $command->companyId;
        $tags = $this->selectedTags($companyId, $command->tagIds);

        // Реклама под статистикой: на теги — из рекламной статистики, «Итого» — из учёта.
        $adLedgers = $this->adLedgerQuery->fetch($companyId, $from, $to, $command->marketplace);
        $byListing = $this->factsQuery->fetch(
            $companyId,
            $from,
            $to,
            $command->marketplace,
            true,
            array_map(static fn (PlPreviewAdLedgerDTO $ledger): string => $ledger->costCategoryId, $adLedgers),
        );
        [$adFacts, $adReconciliation] = $this->adFacts($companyId, $from, $to, $adLedgers);
        $byListing = [...$byListing, ...$adFacts];
        $totals = $this->factsQuery->fetch($companyId, $from, $to, $command->marketplace, false);
        $tagsByListing = $this->tagIdsByListing($companyId, $byListing);

        $tagColumns = [];
        foreach ($tags as $tag) {
            $tagColumns[$tag->id] = [];
        }
        $common = [];
        $tagsOverlap = false;
        foreach ($byListing as $fact) {
            $listingTags = null === $fact->listingId ? [] : ($tagsByListing[$fact->listingId] ?? []);
            if ([] === $listingTags) {
                self::add($common, $fact);
                continue;
            }

            $selectedHits = 0;
            foreach ($listingTags as $tagId) {
                if (isset($tagColumns[$tagId])) {
                    self::add($tagColumns[$tagId], $fact);
                    ++$selectedHits;
                }
            }
            if ($selectedHits > 1) {
                $tagsOverlap = true;
            }
        }

        $total = [];
        foreach ($totals as $fact) {
            self::add($total, $fact);
        }

        $columns = [];
        $financeColumns = [];
        foreach ($tags as $tag) {
            $key = 'tag:'.$tag->id;
            $columns[] = new MarketplacePlPreviewColumn($key, $tag->name, PlPreviewColumnKind::TAG);
            $financeColumns[] = new PlPreviewColumn($key, $tagColumns[$tag->id]);
        }
        $columns[] = new MarketplacePlPreviewColumn(self::COMMON_KEY, 'Общие / без тега', PlPreviewColumnKind::COMMON);
        $financeColumns[] = new PlPreviewColumn(self::COMMON_KEY, $common);
        $columns[] = new MarketplacePlPreviewColumn(self::TOTAL_KEY, 'Итого', PlPreviewColumnKind::TOTAL);
        $financeColumns[] = new PlPreviewColumn(self::TOTAL_KEY, $total);

        $pl = $this->financeFacade->calculatePlPreview($companyId, $from, $to, $financeColumns, self::TOTAL_KEY);

        $costCoverage = $this->costCoverageQuery->fetch($companyId, $from, $to, $command->marketplace);
        $costTotal = $this->costCoverageQuery->fetchTotal($companyId, $from, $to, $command->marketplace);
        $costByStatus = '0';
        foreach ($costCoverage as $row) {
            $costByStatus = bcadd($costByStatus, $row->netAmount, 2);
        }
        $salesCoverage = $this->salesCoverageQuery->fetch($companyId, $from, $to, $command->marketplace);

        return new MarketplacePlPreviewReport(
            from: $from,
            to: $to,
            marketplace: $command->marketplace,
            columns: $columns,
            rows: $pl->rows,
            tagsOverlap: $tagsOverlap,
            unplaced: $pl->unplaced,
            costCoverage: $costCoverage,
            costTotal: bcadd($costTotal, '0', 2),
            costReconciliationDelta: bcsub($costTotal, $costByStatus, 2),
            salesCoverage: $salesCoverage,
            adReconciliation: $adReconciliation,
            warnings: [
                ...$pl->warnings,
                ...(null === $command->marketplace || MarketplaceType::OZON === $command->marketplace ? $this->mappingWarnings->fetch($companyId) : []),
                ...$this->realizationWarnings($companyId, $from, $to, $command->marketplace),
                ...$this->projectWarnings($companyId, $costCoverage, $salesCoverage),
            ],
        );
    }

    /**
     * Реклама по листингам из статистики в знаке документа и остаток на статье без листинга:
     * учёт − статистика по листингам (статистика без товара + расхождение с учётом). Колонки
     * складываются в учётную сумму, «Итого» не меняется.
     *
     * @param list<PlPreviewAdLedgerDTO> $adLedgers
     *
     * @return array{0: list<PlPreviewFactDTO>, 1: list<PlPreviewAdReconciliationDTO>}
     */
    private function adFacts(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, array $adLedgers): array
    {
        $facts = [];
        $reconciliation = [];
        foreach ($adLedgers as $ledger) {
            $spend = $this->adSpend->spendForPeriod($companyId, $ledger->marketplace, $from, $to);

            $allocated = '0';
            foreach ($spend->byListing as $listingId => $amount) {
                $signed = $ledger->signed($amount);
                $facts[] = new PlPreviewFactDTO($ledger->plCategoryId, (string) $listingId, $signed);
                // bcmath_compat объявляет bcadd() без типа результата.
                /** @var numeric-string $allocated */
                $allocated = bcadd($allocated, $signed, 2);
            }

            $rest = bcsub($ledger->ledgerAmount, $allocated, 2);
            if (0 !== bccomp($rest, '0', 2)) {
                $facts[] = new PlPreviewFactDTO($ledger->plCategoryId, null, $rest);
            }

            $ledgerSpend = $ledger->signed($ledger->ledgerAmount);
            $unallocated = $spend->unallocated();
            $reconciliation[] = new PlPreviewAdReconciliationDTO(
                marketplace: $ledger->marketplace,
                ledger: $ledgerSpend,
                statistics: bcadd($spend->total, '0', 2),
                onListings: bcsub($spend->total, $unallocated, 2),
                unallocated: $unallocated,
                difference: bcsub($ledgerSpend, $spend->total, 2),
            );
        }

        return [$facts, $reconciliation];
    }

    /**
     * @param list<string>|null $tagIds
     *
     * @return list<ListingTagDTO>
     */
    private function selectedTags(string $companyId, ?array $tagIds): array
    {
        $tags = $this->tagRepository->listForCompany($companyId);
        if (null === $tagIds) {
            return $tags;
        }

        $wanted = array_flip($tagIds);

        return array_values(array_filter($tags, static fn (ListingTagDTO $tag): bool => isset($wanted[$tag->id])));
    }

    /**
     * Все теги листингов с фактами — не только выбранные: «без тега» значит «без единого тега».
     *
     * @param list<PlPreviewFactDTO> $facts
     *
     * @return array<string, list<string>> listingId → tagIds
     */
    private function tagIdsByListing(string $companyId, array $facts): array
    {
        $listingIds = [];
        foreach ($facts as $fact) {
            if (null !== $fact->listingId) {
                $listingIds[$fact->listingId] = true;
            }
        }

        $result = [];
        foreach (array_chunk(array_keys($listingIds), self::LISTING_CHUNK) as $chunk) {
            foreach ($this->tagAssignments->tagsForListings($companyId, array_map('strval', $chunk)) as $listingId => $listingTags) {
                $result[(string) $listingId] = array_map(static fn (ListingTagDTO $tag): string => $tag->id, $listingTags);
            }
        }

        return $result;
    }

    /**
     * Проведение берёт документ «Реализации» Ozon только целиком; период, который его режет,
     * молча терял бы выручку по реализации.
     *
     * @return list<string>
     */
    private function realizationWarnings(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?MarketplaceType $marketplace): array
    {
        if (null !== $marketplace && MarketplaceType::OZON !== $marketplace) {
            return [];
        }

        $missing = array_map(
            static fn (string $month): string => sprintf(
                'Реализация Ozon за %s не загружена или не обработана. Достоверная выручка с СПП за этот месяц недоступна; начисления без СПП её не заменяют.',
                $month,
            ),
            $this->salesCoverageQuery->fetchMissingRealizationMonths($companyId, $from, $to),
        );

        return [...$missing, ...array_map(
            static fn (array $period): string => sprintf(
                'Реализация Ozon за %s — %s входит в период не целиком и в предпросмотр не попала: выберите период, который содержит её полностью.',
                $period['from']->format('d.m.Y'),
                $period['to']->format('d.m.Y'),
            ),
            $this->salesCoverageQuery->fetchPartialRealizationPeriods($companyId, $from, $to),
        )];
    }

    /**
     * Регистр ОПиУ пропускает операции без проекта; у затрат проект — только из подключения.
     *
     * @param list<PlPreviewCostCoverageDTO> $costCoverage
     * @param list<PlPreviewSalesCoverageDTO> $salesCoverage
     *
     * @return list<string>
     */
    private function projectWarnings(string $companyId, array $costCoverage, array $salesCoverage): array
    {
        $marketplaces = [];
        foreach ([...$costCoverage, ...$salesCoverage] as $row) {
            $marketplaces[$row->marketplace->value] = $row->marketplace;
        }
        ksort($marketplaces);

        $warnings = [];
        foreach ($marketplaces as $marketplace) {
            $connection = $this->connections->findByCompanyIdAndMarketplace($companyId, $marketplace, MarketplaceConnectionType::SELLER);
            if (null === $connection?->getProjectDirectionId()) {
                $warnings[] = sprintf(
                    '%s: у подключения не задан проект ОПиУ — затраты и продажи по правилам без своего проекта при проведении в отчёт ОПиУ не попадут.',
                    $marketplace->getDisplayName(),
                );
            }
        }

        return $warnings;
    }

    /**
     * @param array<string, string> $amounts
     */
    private static function add(array &$amounts, PlPreviewFactDTO $fact): void
    {
        // bcmath_compat объявляет bcadd() без типа результата.
        /** @var numeric-string $sum */
        $sum = bcadd($amounts[$fact->plCategoryId] ?? '0', $fact->amount, 2);
        $amounts[$fact->plCategoryId] = $sum;
    }
}
