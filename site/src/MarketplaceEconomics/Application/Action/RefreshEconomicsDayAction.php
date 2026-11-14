<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\Action;

use App\Inventory\Facade\InventoryFacade;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Facade\MarketplaceAdsFacade;
use App\MarketplaceEconomics\Domain\DayEconomics;
use App\MarketplaceEconomics\Domain\DayEconomicsCalculator;
use App\MarketplaceEconomics\Infrastructure\Query\EconomicsStore;
use App\MarketplaceOperations\Application\DTO\DayWindow;
use App\MarketplaceOperations\Enum\CascadeLevel;
use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;

/**
 * Пересчёт витрины одного дня кабинета. Транзакцией владеет вызывающая команда: `takeDay`
 * блокирует день канона и снимает отметку «грязный» в той же транзакции, что запись витрины.
 */
final readonly class RefreshEconomicsDayAction
{
    /** Меняется при смене правил расчёта витрины; дни пересчитывает `--from` (ночной `--recent` — последние). */
    public const RULES_VERSION = 'economics-1';

    public function __construct(
        private MarketplaceOperationsFacadeInterface $operations,
        private MarketplaceFacade $marketplace,
        private MarketplaceAdsFacade $ads,
        private InventoryFacade $inventory,
        private EconomicsStore $store,
        private DayEconomicsCalculator $calculator,
    ) {
    }

    public function __invoke(DayWindow $day): DayEconomics
    {
        $operations = $this->operations->takeDay($day);
        $date = new \DateTimeImmutable($day->date);

        $sold = $needAds = $needStock = false;
        $listingIds = [];
        foreach ($operations as $op) {
            if (null !== $op->listingId && CascadeLevel::REVENUE === $op->kind->level()) {
                $listingIds[$op->listingId] = true;
                $sold = true;
            }
            if (null === $op->listingId) {
                $needAds = $needAds || CascadeLevel::MD2 === $op->kind->level();
                $needStock = $needStock || OperationKind::STORAGE === $op->kind;
            }
        }

        // канал канона совпадает с MarketplaceType::value легаси ('ozon', 'wildberries')
        $costPrices = $sold ? $this->marketplace->getCostPricesForListingsOnDate($day->companyId, array_keys($listingIds), $date) : [];
        // ponytail: доли рекламы — по всем листингам компании в канале; у компании с несколькими кабинетами
        // одного канала доли смешаются — сузить по кабинету листинга, когда такие компании включат
        $adSpend = $needAds ? $this->ads->getAdSpendByListingForPeriod($day->companyId, $date, $date, $day->channel) : [];
        $stock = $needStock ? $this->inventory->getStockQtyByListingOnReportDate($day->companyId, $date, $day->channel)->qtyByListingId : [];

        $economics = $this->calculator->calculate($operations, $costPrices, $adSpend, $stock);
        $this->store->replaceDay($day, $economics, self::RULES_VERSION);

        return $economics;
    }
}
