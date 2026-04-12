<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Ozon;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdRawDataParserInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Парсер rawPayload рекламной статистики Ozon.
 *
 * TODO: уточнить реальный формат ответа Performance API Ozon.
 * Ожидаемая структура:
 * {
 *   "rows": [
 *     {"campaign_id": "123", "campaign_name": "...", "sku": "456",
 *      "spend": 150.50, "views": 1000, "clicks": 50}
 *   ]
 * }
 */
final class OzonAdRawDataParser implements AdRawDataParserInterface
{
    /** Точность хранения cost при агрегации — избегаем кумулятивной ошибки округления. */
    private const AGGREGATION_SCALE = 8;

    /** Финальная точность cost в AdRawEntry — округление HALF-UP применяется только один раз. */
    private const FINAL_SCALE = 2;

    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(string $marketplace): bool
    {
        return $marketplace === MarketplaceType::OZON->value;
    }

    /**
     * {@inheritdoc}
     */
    public function parse(string $rawPayload): array
    {
        $data = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $rows = $data['rows'] ?? [];
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        /** @var array<string, array{campaignId: string, campaignName: string, parentSku: string, cost: string, impressions: int, clicks: int}> $aggregated */
        $aggregated = [];
        $skippedNonArray = 0;
        $skippedMissingFields = 0;

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                ++$skippedNonArray;
                continue;
            }

            $campaignId = isset($row['campaign_id']) ? (string) $row['campaign_id'] : '';
            $parentSku  = isset($row['sku']) ? (string) $row['sku'] : '';
            if ($campaignId === '' || $parentSku === '') {
                ++$skippedMissingFields;
                $this->logger->warning(
                    'Ozon ad raw row skipped: missing required fields campaign_id/sku',
                    [
                        'index'              => $index,
                        'has_campaign_id'    => $campaignId !== '',
                        'has_sku'            => $parentSku !== '',
                        'marketplace'        => MarketplaceType::OZON->value,
                    ],
                );
                continue;
            }

            $cost        = number_format((float) ($row['spend'] ?? 0), self::AGGREGATION_SCALE, '.', '');
            $impressions = (int) ($row['views'] ?? 0);
            $clicks      = (int) ($row['clicks'] ?? 0);
            $key         = $campaignId . '|' . $parentSku;

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'campaignId'   => $campaignId,
                    'campaignName' => isset($row['campaign_name']) ? (string) $row['campaign_name'] : '',
                    'parentSku'    => $parentSku,
                    'cost'         => $cost,
                    'impressions'  => $impressions,
                    'clicks'       => $clicks,
                ];

                continue;
            }

            $aggregated[$key]['cost']        = bcadd($aggregated[$key]['cost'], $cost, self::AGGREGATION_SCALE);
            $aggregated[$key]['impressions'] += $impressions;
            $aggregated[$key]['clicks']      += $clicks;
        }

        if ($skippedNonArray > 0 || $skippedMissingFields > 0) {
            $this->logger->info(
                'Ozon ad raw payload: some rows were skipped during parsing',
                [
                    'total_rows'             => count($rows),
                    'skipped_non_array'      => $skippedNonArray,
                    'skipped_missing_fields' => $skippedMissingFields,
                    'aggregated_entries'     => count($aggregated),
                    'marketplace'            => MarketplaceType::OZON->value,
                ],
            );
        }

        return array_map(
            static fn(array $r) => new AdRawEntry(
                campaignId:   $r['campaignId'],
                campaignName: $r['campaignName'],
                parentSku:    $r['parentSku'],
                // HALF-UP round до FINAL_SCALE применяется один раз к агрегату
                // (ad cost не бывает отрицательным, поэтому достаточно +0.005).
                cost:         bcadd($r['cost'], '0.005', self::FINAL_SCALE),
                impressions:  $r['impressions'],
                clicks:       $r['clicks'],
            ),
            array_values($aggregated),
        );
    }
}
