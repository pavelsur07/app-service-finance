<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Ozon;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdRawDataParserInterface;

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

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $campaignId = isset($row['campaign_id']) ? (string) $row['campaign_id'] : '';
            $parentSku  = isset($row['sku']) ? (string) $row['sku'] : '';
            if ($campaignId === '' || $parentSku === '') {
                continue;
            }

            $cost        = number_format((float) ($row['spend'] ?? 0), 2, '.', '');
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

            $aggregated[$key]['cost']        = bcadd($aggregated[$key]['cost'], $cost, 2);
            $aggregated[$key]['impressions'] += $impressions;
            $aggregated[$key]['clicks']      += $clicks;
        }

        return array_map(
            static fn(array $r) => new AdRawEntry(
                campaignId:   $r['campaignId'],
                campaignName: $r['campaignName'],
                parentSku:    $r['parentSku'],
                cost:         $r['cost'],
                impressions:  $r['impressions'],
                clicks:       $r['clicks'],
            ),
            array_values($aggregated),
        );
    }
}
