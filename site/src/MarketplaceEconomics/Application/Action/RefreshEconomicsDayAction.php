<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\Action;

use App\Inventory\Facade\InventoryFacade;
use App\Marketplace\Enum\AdSpendKind;
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
    /** Меняется при смене правил расчёта витрины — команда пересчитает все дни с прежней версией сама. */
    public const RULES_VERSION = 'economics-2';

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

        $sold = $needStock = false;
        $listingIds = $adKinds = [];
        foreach ($operations as $op) {
            if (null !== $op->listingId && CascadeLevel::REVENUE === $op->kind->level()) {
                $listingIds[$op->listingId] = true;
                $sold = true;
            }
            if (null === $op->listingId) {
                if (CascadeLevel::MD2 === $op->kind->level()) {
                    $adKinds[$op->kind->value] = true;
                }
                $needStock = $needStock || OperationKind::STORAGE === $op->kind;
            }
        }

        // канал канона совпадает с MarketplaceType::value легаси ('ozon', 'wildberries')
        $costPrices = $sold ? $this->marketplace->getCostPricesForListingsOnDate($day->companyId, array_keys($listingIds), $date) : [];
        // ponytail: доли рекламы — по всем листингам компании в канале; у компании с несколькими кабинетами
        // одного канала доли смешаются — сузить по кабинету листинга, когда такие компании включат
        $adSpend = [];
        foreach ([OperationKind::ADS_CPC->value => AdSpendKind::CLICKS, OperationKind::ADS_PAY_PER_ORDER->value => AdSpendKind::PAY_PER_ORDER] as $kind => $spendKind) {
            if (isset($adKinds[$kind])) {
                $adSpend[$kind] = $this->ads->getAdSpendByListingForPeriod($day->companyId, $date, $date, $day->channel, $spendKind);
            }
        }
        $stock = $needStock ? $this->inventory->getStockQtyByListingOnReportDate($day->companyId, $date, $day->channel)->qtyByListingId : [];

        $economics = $this->calculator->calculate($operations, $costPrices, $adSpend, $stock);
        $this->store->replaceDay($day, $economics, self::RULES_VERSION);

        return $economics;
    }
}
