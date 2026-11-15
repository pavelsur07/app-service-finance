<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Application\Action;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceOperations\Application\DTO\DayOperations;
use App\MarketplaceOperations\Application\DTO\OperationInput;
use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\MarketplaceWb\Domain\AdsDayTranslator;
use App\MarketplaceWb\Domain\SalesReportTranslator;
use App\MarketplaceWb\Domain\WbComponent;
use App\MarketplaceWb\Infrastructure\Query\WbTranslationStore;
use App\Shared\Domain\ValueObject\Money;
use App\SourceFacts\Application\DTO\SourcePeriod;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;

/**
 * Один день кабинета WB: строки отчёта → канон + карантин + итоги дня. Транзакцией владеет
 * вызывающая команда; блокировку дня берёт `replaceDay`. Повтор даёт тот же результат.
 */
final readonly class TranslateWbDayAction
{
    public const RESOURCE = 'wb.finance.sales-report.daily';
    /** Канал канона = `MarketplaceType::WILDBERRIES->value`. */
    public const CHANNEL = 'wildberries';
    /** Строка с артикулом без размера разнесена по размерам артикула (продажи дня, без продаж — поровну). */
    public const SIZE_ALLOCATION_KEY = 'wb_size_sales_share';

    public function __construct(
        private SourceFactsFacadeInterface $sourceFacts,
        private MarketplaceOperationsFacadeInterface $operations,
        private MarketplaceFacade $marketplace,
        private WbTranslationStore $store,
        private SalesReportTranslator $translator,
    ) {
    }

    /**
     * `$adsRevision` — текущая ревизия рекламы того же дня (`wb.ads.daily`, 0 — нет): расход кампаний
     * разносится по SKU пропорционально их статистике.
     *
     * @return array{operations: int, quarantined: int, unresolved: int}
     */
    public function __invoke(SourcePeriod $period, int $adsRevision = 0): array
    {
        $date = $period->periodKey;
        $facts = $this->sourceFacts->activeFacts($period->companyId, $period->connectionRef, self::RESOURCE, $date);

        // ponytail: итоги дня в рублях — у кабинетов WB (РФ) другой валюты нет; Money::add бросит на
        // чужой валюте, день упадёт с ошибкой, а не посчитается неверно.
        $total = $canon = $quarantined = Money::fromMinor(0, 'RUB');
        $components = [];
        foreach ($facts as $fact) {
            foreach ($this->translator->translate($fact->sourceKey, $fact->payload) as $component) {
                $components[] = $component;
                if (!SalesReportTranslator::isInformational($component)) {
                    $total = $total->add($component->amount);
                }
            }
        }
        if (0 !== $adsRevision) {
            foreach ($this->sourceFacts->activeFacts($period->companyId, $period->connectionRef, AdsDayTranslator::RESOURCE, $date) as $fact) {
                foreach (AdsDayTranslator::translate($fact->sourceKey, $fact->payload) as $component) {
                    $components[] = $component;
                    $total = $total->add($component->amount);
                }
            }
        }

        $undecided = array_values(array_unique(array_map(
            static fn (WbComponent $c): string => $c->sourceType,
            array_filter($components, static fn (WbComponent $c): bool => null === $c->kind),
        )));
        $kinds = $this->store->resolveKinds($undecided, $period->companyId, $date);
        $listings = $this->listings($period->companyId, $components);

        // проданные штуки размеров за день — веса для строк без размера (логистика, реклама по nmId)
        $soldQty = [];
        foreach ($components as $component) {
            if (OperationKind::SALE === ($component->kind ?? $kinds[$component->sourceType] ?? null) && null !== $component->nmId) {
                $own = self::allocate($listings[$component->nmId] ?? [], $component->size, 1, []);
                if (1 === \count($own)) {
                    $listingId = (string) array_key_first($own);
                    $soldQty[$listingId] = ($soldQty[$listingId] ?? 0) + abs($component->quantity ?? 0);
                }
            }
        }

        $operations = [];
        $quarantine = [];
        $unresolved = 0;
        foreach ($components as $component) {
            $kind = $component->kind ?? $kinds[$component->sourceType] ?? null;
            if (null === $kind) {
                $row = $quarantine[$component->sourceType] ?? ['amountMinor' => 0, 'currency' => $component->amount->currency(), 'rows' => 0, 'refs' => []];
                $row['amountMinor'] += $component->amount->amountMinor();
                ++$row['rows'];
                $row['refs'][] = $component->sourceKey;
                $quarantine[$component->sourceType] = $row;
                $quarantined = $quarantined->add($component->amount);
                continue;
            }

            $parts = null === $component->nmId ? [] : self::allocate($listings[$component->nmId] ?? [], $component->size, $component->amount->amountMinor(), $soldQty);
            if (null !== $component->nmId && [] === $parts) {
                ++$unresolved;
            }
            $isAds = AdsDayTranslator::SOURCE_TYPE === $component->sourceType;
            if ([] === $parts) {
                $operations[] = new OperationInput($kind, $component->sourceType, $component->amount, [$component->sourceKey], null, $component->quantity, null);
            } elseif (1 === \count($parts)) {
                $operations[] = new OperationInput($kind, $component->sourceType, $component->amount, [$component->sourceKey], (string) array_key_first($parts), $component->quantity, $isAds ? AdsDayTranslator::ALLOCATION_KEY : null);
            } else {
                foreach ($parts as $listingId => $minor) {
                    $operations[] = new OperationInput($kind, $component->sourceType, Money::fromMinor($minor, $component->amount->currency()), [$component->sourceKey], (string) $listingId, null,
                        $isAds ? AdsDayTranslator::ALLOCATION_KEY : self::SIZE_ALLOCATION_KEY);
                }
            }
            if (!SalesReportTranslator::isInformational($component)) {
                $canon = $canon->add($component->amount);
            }
        }

        $this->operations->replaceDay(new DayOperations(
            $period->companyId,
            self::CHANNEL,
            $period->connectionRef,
            new \DateTimeImmutable($date),
            SalesReportTranslator::RULES_VERSION,
            $operations,
        ));

        $this->store->saveDay($period->companyId, $period->connectionRef, $date, SalesReportTranslator::RULES_VERSION, [
            'revision' => $period->revision,
            'adsRevision' => $adsRevision,
            'facts' => \count($facts),
            'totalMinor' => $total->amountMinor(),
            'canonMinor' => $canon->amountMinor(),
            'quarantineMinor' => $quarantined->amountMinor(),
            'currency' => $total->currency(),
            'unresolved' => $unresolved,
        ], $quarantine);

        return ['operations' => \count($operations), 'quarantined' => \count($quarantine), 'unresolved' => $unresolved];
    }

    /**
     * Куда ставится сумма строки с артикулом: размер известен — на листинг этого размера (размеры сравниваются
     * без регистра и кириллических двойников латиницы); у артикула один листинг — на него; размер неизвестен
     * (`null` у рекламы, `UNKNOWN` у отчёта) — по размерам артикула пропорционально проданным за день штукам,
     * без продаж — поровну. Известный размер без листинга — `[]` (без SKU: листинга не хватает).
     *
     * @param list<array{id: string, size: string}> $found листинги артикула
     * @param array<string, int> $soldQty listingId → продано за день
     *
     * @return array<string, int> listingId → сумма, Σ = $amountMinor; `[]` — без SKU
     */
    public static function allocate(array $found, ?string $size, int $amountMinor, array $soldQty): array
    {
        if ([] === $found) {
            return [];
        }
        if (!self::sizeUnknown($size)) {
            foreach ($found as $listing) {
                if (self::normalizeSize($listing['size']) === self::normalizeSize((string) $size)) {
                    return [$listing['id'] => $amountMinor];
                }
            }

            return 1 === \count($found) ? [$found[0]['id'] => $amountMinor] : [];
        }
        if (1 === \count($found)) {
            return [$found[0]['id'] => $amountMinor];
        }
        $weights = [];
        foreach ($found as $listing) {
            $weights[$listing['id']] = $soldQty[$listing['id']] ?? 0;
        }
        if (0 === array_sum($weights)) {
            $weights = array_map(static fn (): int => 1, $weights);
        }
        $parts = [];
        foreach (AdsDayTranslator::split($amountMinor, $weights) as $listingId => $minor) {
            $parts[(string) $listingId] = $minor;
        }

        return $parts;
    }

    private static function sizeUnknown(?string $size): bool
    {
        return null === $size || SalesReportTranslator::UNKNOWN_SIZE === $size;
    }

    private static function normalizeSize(string $size): string
    {
        return strtr(mb_strtoupper(trim($size)), ['А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X']);
    }

    /**
     * @param list<WbComponent> $components
     *
     * @return array<string, list<array{id: string, size: string}>> nmId → листинги
     */
    private function listings(string $companyId, array $components): array
    {
        $nmIds = array_values(array_unique(array_filter(array_map(static fn (WbComponent $c): ?string => $c->nmId, $components))));
        $listings = [];
        foreach ($this->marketplace->findListingsByMarketplaceSkus($companyId, self::CHANNEL, $nmIds) as $nmId => $found) {
            $listings[(string) $nmId] = array_map(static fn (array $l): array => ['id' => $l['id'], 'size' => $l['size']], $found);
        }

        return $listings;
    }
}
