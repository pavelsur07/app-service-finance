<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\DTO\OzonAllSkuPromoCampaignSpend;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;

/**
 * Расход кампаний ALL_SKU_PROMO кабинета из сохранённого сырья Ozon Performance:
 * - кампании этого типа — из последнего загруженного списка кампаний;
 * - расход по дням — отчёт `expense` (moneySpent + prepaymentSpent; бонусы — не деньги), при
 *   перекрытии окон берётся последняя загруженная версия дня;
 * - товары — все загруженные версии `search_promo/v2/products` кампании (объединение SKU).
 *
 * Отчёт по заказам эти кампании не покрывает (Ozon игнорирует в нём фильтр кампании), поэтому
 * SKU расхода нет — его распределяет MarketplaceAds.
 */
final readonly class OzonAllSkuPromoSpendReader
{
    public const string ADV_OBJECT_TYPE = 'ALL_SKU_PROMO';

    public function __construct(
        private IngestRawRecordRepository $rawRecordRepository,
        private RawStorageFacade $rawStorageFacade,
    ) {
    }

    /**
     * @return list<OzonAllSkuPromoCampaignSpend> кампании с расходом в периоде
     */
    public function read(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $campaignIds = $this->campaignIds($companyId);
        if ([] === $campaignIds) {
            return [];
        }

        $fromDay = $from->format('Y-m-d');
        $toDay = $to->format('Y-m-d');
        $spend = [];
        foreach ($this->latestIds($companyId, OzonResourceType::PERFORMANCE_EXPENSE_STATISTICS, 'performance-expense-stats:%') as $recordId) {
            foreach ($this->rawStorageFacade->read($recordId, $companyId) as $row) {
                $campaignId = self::scalar($row['id'] ?? null);
                $day = substr(self::scalar($row['date'] ?? null), 0, 10);
                if (!isset($campaignIds[$campaignId]) || $day < $fromDay || $day > $toDay) {
                    continue;
                }
                $spend[$campaignId][$day] = bcadd(self::money($row['moneySpent'] ?? null), self::money($row['prepaymentSpent'] ?? null), 2);
            }
        }

        $result = [];
        foreach ($spend as $campaignId => $days) {
            $days = array_filter($days, static fn (string $amount): bool => bccomp($amount, '0', 2) > 0);
            if ([] === $days) {
                continue;
            }
            ksort($days);
            $result[] = new OzonAllSkuPromoCampaignSpend((string) $campaignId, $this->skus($companyId, (string) $campaignId), $days);
        }

        return $result;
    }

    /**
     * @return array<string, true>
     */
    private function campaignIds(string $companyId): array
    {
        $recordIds = $this->latestIds($companyId, OzonResourceType::PERFORMANCE_CAMPAIGNS, 'performance-campaigns:%');
        $latest = end($recordIds);
        $ids = [];
        foreach (false === $latest ? [] : $this->rawStorageFacade->read($latest, $companyId) as $row) {
            $id = self::scalar($row['id'] ?? null);
            if ('' !== $id && self::ADV_OBJECT_TYPE === ($row['advObjectType'] ?? null)) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function skus(string $companyId, string $campaignId): array
    {
        // ponytail: все версии списка товаров кампании (по объекту на окно загрузки, ~30 в месяц);
        // при росте — только последнее окно.
        $skus = [];
        foreach ($this->latestIds($companyId, OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS, 'performance-search-promo-products:'.$campaignId.':%') as $recordId) {
            foreach ($this->rawStorageFacade->read($recordId, $companyId) as $row) {
                $sku = self::scalar($row['sku'] ?? null);
                if ('' !== $sku) {
                    $skus[$sku] = true;
                }
            }
        }
        $skus = array_map('strval', array_keys($skus));
        sort($skus);

        return $skus;
    }

    /**
     * @return list<string>
     */
    private function latestIds(string $companyId, string $resourceType, string $externalIdLike): array
    {
        return $this->rawRecordRepository->findLatestIdsByCompanyAndResource($companyId, $resourceType, $externalIdLike);
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function money(mixed $value): string
    {
        $number = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], self::scalar($value));

        return is_numeric($number) ? bcadd($number, '0', 2) : '0.00';
    }
}
