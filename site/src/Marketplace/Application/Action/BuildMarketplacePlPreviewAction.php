<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Company\Application\DTO\ProjectDirectionDTO;
use App\Company\Facade\CompanyFacade;
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
use App\Marketplace\Enum\PlPreviewColumnMode;
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
 * Предпросмотр ОПиУ: что покажет ОПиУ, если провести нормализованные данные периода по текущему
 * маппингу. Только чтение — ни документов ОПиУ, ни регистра, ни отметок у строк.
 *
 * Колонки в режиме TAGS: теги (полная сумма листинга в каждый его тег), «Общие / без тега» (без
 * листинга или листинг без тегов). В режиме PROJECTS: проекты ОПиУ (проект правила маппинга, иначе
 * проект Seller-подключения — как CloseMonthStageAction), «Без проекта» — только если не пусто.
 * Последняя колонка — «Итого» (отдельный расчёт без разреза по листингам — совпадает с
 * проведением, см. PlPreviewFactsQuery).
 */
final readonly class BuildMarketplacePlPreviewAction
{
    public const int MAX_PERIOD_DAYS = 366;
    public const string COMMON_KEY = 'common';
    public const string NO_PROJECT_KEY = 'no_project';
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
        private CompanyFacade $companyFacade,
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

        // Реклама под статистикой: на теги — из рекламной статистики, на проекты и «Итого» — из учёта.
        // Сверку учёта со статистикой показываем в обоих режимах.
        $adLedgers = $this->adLedgerQuery->fetch($companyId, $from, $to, $command->marketplace);
        [$adFacts, $adReconciliation] = $this->adFacts($companyId, $from, $to, $adLedgers);
        $totals = $this->factsQuery->fetch($companyId, $from, $to, $command->marketplace, false);

        $total = [];
        foreach ($totals as $fact) {
            self::add($total, $fact);
        }

        $tagsOverlap = false;
        $columnWarnings = [];
        if (PlPreviewColumnMode::PROJECTS === $command->mode) {
            [$columns, $financeColumns, $columnWarnings] = $this->projectColumns($command, $from, $to);
        } else {
            [$columns, $financeColumns, $tagsOverlap] = $this->tagColumns($command, $from, $to, $adLedgers, $adFacts);
        }
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
            mode: $command->mode,
            warnings: [
                ...$pl->warnings,
                ...(null === $command->marketplace || MarketplaceType::OZON === $command->marketplace ? $this->mappingWarnings->fetch($companyId) : []),
                ...$this->realizationWarnings($companyId, $from, $to, $command->marketplace),
                ...$this->projectWarnings($companyId, $costCoverage, $salesCoverage),
                ...$columnWarnings,
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
     * @param list<PlPreviewAdLedgerDTO> $adLedgers
     * @param list<PlPreviewFactDTO> $adFacts реклама по листингам из статистики
     *
     * @return array{0: list<MarketplacePlPreviewColumn>, 1: list<PlPreviewColumn>, 2: bool}
     */
    private function tagColumns(
        BuildMarketplacePlPreviewCommand $command,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $adLedgers,
        array $adFacts,
    ): array {
        $companyId = $command->companyId;
        $tags = $this->selectedTags($companyId, $command->tagIds);
        $byListing = $this->factsQuery->fetch(
            $companyId,
            $from,
            $to,
            $command->marketplace,
            true,
            array_map(static fn (PlPreviewAdLedgerDTO $ledger): string => $ledger->costCategoryId, $adLedgers),
        );
        $byListing = [...$byListing, ...$adFacts];
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

        $columns = [];
        $financeColumns = [];
        foreach ($tags as $tag) {
            $key = 'tag:'.$tag->id;
            $columns[] = new MarketplacePlPreviewColumn($key, $tag->name, PlPreviewColumnKind::TAG);
            $financeColumns[] = new PlPreviewColumn($key, $tagColumns[$tag->id]);
        }
        $columns[] = new MarketplacePlPreviewColumn(self::COMMON_KEY, 'Общие / без тега', PlPreviewColumnKind::COMMON);
        $financeColumns[] = new PlPreviewColumn(self::COMMON_KEY, $common);

        return [$columns, $financeColumns, $tagsOverlap];
    }

    /**
     * Реклама здесь — учётной суммой, как при проведении: раскладка по статистике нужна только
     * листингам. Проект суммы — проект правила, иначе проект Seller-подключения маркетплейса.
     * Проект, которого нет среди проектов компании (удалён, чужой), — в «Без проекта» с
     * предупреждением: проведение на нём упадёт, а тихий пропуск разошёлся бы с «Итого».
     *
     * @return array{0: list<MarketplacePlPreviewColumn>, 1: list<PlPreviewColumn>, 2: list<string>}
     */
    private function projectColumns(BuildMarketplacePlPreviewCommand $command, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $companyId = $command->companyId;
        $allProjects = $this->companyFacade->listProjectDirections($companyId);
        $known = [];
        foreach ($allProjects as $project) {
            $known[$project->id] = true;
        }
        $projects = self::selectedProjects($allProjects, $command->projectIds);
        $facts = $this->factsQuery->fetch($companyId, $from, $to, $command->marketplace, false, [], true);

        $projectColumns = [];
        foreach ($projects as $project) {
            $projectColumns[$project->id] = [];
        }
        $noProject = [];
        $connectionProjects = [];
        $unknownProjectMarketplaces = [];
        foreach ($facts as $fact) {
            $projectId = $fact->projectId;
            if (null === $projectId && null !== $fact->marketplace) {
                $key = $fact->marketplace->value;
                if (!\array_key_exists($key, $connectionProjects)) {
                    $connectionProjects[$key] = $this->connections
                        ->findByCompanyIdAndMarketplace($companyId, $fact->marketplace, MarketplaceConnectionType::SELLER)
                        ?->getProjectDirectionId();
                }
                $projectId = $connectionProjects[$key];
            }

            if (null !== $projectId && !isset($known[$projectId])) {
                if (null !== $fact->marketplace) {
                    $unknownProjectMarketplaces[$fact->marketplace->value] = $fact->marketplace;
                }
                $projectId = null;
            }

            if (null === $projectId) {
                self::add($noProject, $fact);
            } elseif (isset($projectColumns[$projectId])) {
                self::add($projectColumns[$projectId], $fact);
            }
        }

        $columns = [];
        $financeColumns = [];
        foreach ($projects as $project) {
            $key = 'project:'.$project->id;
            $columns[] = new MarketplacePlPreviewColumn($key, $project->name, PlPreviewColumnKind::PROJECT, $project->level);
            $financeColumns[] = new PlPreviewColumn($key, $projectColumns[$project->id]);
        }
        if (array_filter($noProject, static fn (string $amount): bool => 0 !== bccomp($amount, '0', 2))) {
            $columns[] = new MarketplacePlPreviewColumn(self::NO_PROJECT_KEY, 'Без проекта', PlPreviewColumnKind::NO_PROJECT);
            $financeColumns[] = new PlPreviewColumn(self::NO_PROJECT_KEY, $noProject);
        }

        ksort($unknownProjectMarketplaces);
        $warnings = array_values(array_map(
            static fn (MarketplaceType $marketplace): string => sprintf(
                '%s: проект ОПиУ из правила или подключения не найден среди проектов компании — суммы показаны в «Без проекта», проведение на нём завершится ошибкой.',
                $marketplace->getDisplayName(),
            ),
            $unknownProjectMarketplaces,
        ));

        return [$columns, $financeColumns, $warnings];
    }

    /**
     * @param list<ProjectDirectionDTO> $projects
     * @param list<string>|null $projectIds
     *
     * @return list<ProjectDirectionDTO>
     */
    private static function selectedProjects(array $projects, ?array $projectIds): array
    {
        if (null === $projectIds) {
            return $projects;
        }

        $wanted = array_flip($projectIds);

        return array_values(array_filter($projects, static fn (ProjectDirectionDTO $project): bool => isset($wanted[$project->id])));
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
