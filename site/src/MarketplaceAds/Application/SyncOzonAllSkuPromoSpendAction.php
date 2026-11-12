<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application;

use App\Ingestion\Facade\IngestionFacade;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Application\DTO\OzonAllSkuPromoSyncResult;
use App\MarketplaceAds\Domain\Service\AdCostDistributor;
use App\MarketplaceAds\Domain\Service\ListingSalesProviderInterface;
use App\MarketplaceAds\Domain\ValueObject\OzonSearchPromoCampaign;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Оплата за заказ Ozon «все товары» (кампании ALL_SKU_PROMO) — в рекламную статистику.
 *
 * Ozon отдаёт расход этих кампаний только по кампании за день, без SKU. Расход дня распределяется по
 * листингам товаров кампании пропорционально продажам (штуки) за этот день; день без продаж — по
 * продажам за весь период синхронизации; без продаж и за период или без листингов — `__unallocated__`
 * (в статистике без товара). Решение Владельца 12.11.2026.
 *
 * На (кабинет, день) — AdRawDocument `ozon-search-promo:all-sku:<день>`, кампания `all-sku-promo`,
 * строки по SKU. Запись и повтор — как у SyncOzonSearchPromoSpendAction.
 */
final readonly class SyncOzonAllSkuPromoSpendAction
{
    public function __construct(
        private IngestionFacade $ingestionFacade,
        private ListingSalesProviderInterface $listingSalesProvider,
        private AdCostDistributor $costDistributor,
        private AdRawDocumentRepository $rawDocumentRepository,
        private ProcessAdRawDocumentAction $processAction,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): OzonAllSkuPromoSyncResult
    {
        Assert::uuid($companyId);
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        Assert::true($from <= $to, 'Пустой период.');

        $campaigns = $this->ingestionFacade->getOzonAllSkuPromoSpend($companyId, $from, $to);

        // Листинги товаров всех кампаний и их продажи по дням периода: один запрос листингов,
        // по запросу продаж на день.
        $skus = [];
        foreach ($campaigns as $campaign) {
            array_push($skus, ...$campaign->skus);
        }
        $skus = array_values(array_unique($skus));
        $listingsBySku = [] === $skus ? [] : $this->listingSalesProvider->findListingsByParentSkus($companyId, MarketplaceType::OZON->value, $skus);
        $listingIds = [];
        foreach ($listingsBySku as $listings) {
            foreach ($listings as $listing) {
                $listingIds[] = $listing['id'];
            }
        }
        $salesByDay = [];
        $periodSales = [];
        if ([] !== $campaigns && [] !== $listingIds) {
            for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
                $sales = $this->listingSalesProvider->getSalesQuantitiesByListings($companyId, $listingIds, $date);
                $salesByDay[$date->format('Y-m-d')] = $sales;
                foreach ($sales as $listingId => $quantity) {
                    $periodSales[$listingId] = ($periodSales[$listingId] ?? 0) + $quantity;
                }
            }
        }

        $total = '0';
        $unallocated = '0';
        $days = 0;
        for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
            $day = $date->format('Y-m-d');
            /** @var array<string, string> $spendBySku */
            $spendBySku = [];
            foreach ($campaigns as $campaign) {
                $spend = $campaign->spendByDay[$day] ?? null;
                if (null === $spend) {
                    continue;
                }
                $total = bcadd($total, $spend, 2);
                $listings = [];
                foreach ($campaign->skus as $sku) {
                    array_push($listings, ...($listingsBySku[$sku] ?? []));
                }
                $sales = self::weights($listings, $salesByDay[$day] ?? [], $periodSales);
                if (null === $sales) {
                    $unallocated = bcadd($unallocated, $spend, 2);
                    $spendBySku[AdRawEntry::UNALLOCATED_PARENT_SKU] = bcadd($spendBySku[AdRawEntry::UNALLOCATED_PARENT_SKU] ?? '0', $spend, 2);
                    continue;
                }
                $parentSkuByListing = array_column($listings, 'parentSku', 'id');
                foreach ($this->costDistributor->distribute($listings, $sales, $spend, 0, 0) as $line) {
                    $sku = $parentSkuByListing[$line->listingId];
                    $spendBySku[$sku] = bcadd($spendBySku[$sku] ?? '0', $line->cost, 2);
                }
            }

            $rows = [];
            foreach ($spendBySku as $sku => $spend) {
                if (0 !== bccomp($spend, '0', 2)) {
                    $rows[] = ['sku' => (string) $sku, 'spend' => $spend, 'views' => 0, 'clicks' => 0];
                }
            }
            if ($this->writeDay($companyId, $date, $rows)) {
                ++$days;
            }
        }

        return new OzonAllSkuPromoSyncResult(count($campaigns), $days, $total, $unallocated);
    }

    /**
     * Продажи листингов кампании за день, иначе за период; null — продаж нет или листингов нет.
     *
     * @param list<array{id: string, parentSku: string}> $listings
     * @param array<string, int> $daySales
     * @param array<string, int> $periodSales
     *
     * @return array<string, int>|null
     */
    private static function weights(array $listings, array $daySales, array $periodSales): ?array
    {
        foreach ([$daySales, $periodSales] as $sales) {
            $total = 0;
            foreach ($listings as $listing) {
                $total += $sales[$listing['id']] ?? 0;
            }
            if ($total > 0) {
                return $sales;
            }
        }

        return null;
    }

    /**
     * @param list<array{sku: string, spend: string, views: int, clicks: int}> $rows
     *
     * @return bool день записан и спроецирован
     */
    private function writeDay(string $companyId, \DateTimeImmutable $date, array $rows): bool
    {
        // ponytail: та же запись дня, что в SyncOzonSearchPromoSpendAction; общий класс — при третьем виде.
        $sourceKey = OzonSearchPromoCampaign::ALL_SKU_SOURCE_KEY_PREFIX.$date->format('Y-m-d');
        $document = $this->rawDocumentRepository->findBySourceKey($companyId, MarketplaceType::OZON->value, $sourceKey);
        if ([] === $rows && null === $document) {
            return false;
        }

        $payload = json_encode([
            'campaigns' => [[
                'campaign_id' => OzonSearchPromoCampaign::ALL_SKU_CAMPAIGN_ID,
                'campaign_name' => OzonSearchPromoCampaign::ALL_SKU_CAMPAIGN_NAME,
                'rows' => $rows,
            ]],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        if (null === $document) {
            $document = new AdRawDocument($companyId, MarketplaceType::OZON, $date, $payload, $sourceKey);
            $this->rawDocumentRepository->save($document);
        } elseif ($payload !== $document->getRawPayload() || AdRawDocumentStatus::PROCESSED !== $document->getStatus()) {
            $document->updatePayload($payload);
        } else {
            return false;
        }

        // Сырьё дня сохраняется до проекции: при её сбое день остаётся в DRAFT для повтора.
        $this->entityManager->flush();
        ($this->processAction)($companyId, $document->getId());

        return true;
    }
}
