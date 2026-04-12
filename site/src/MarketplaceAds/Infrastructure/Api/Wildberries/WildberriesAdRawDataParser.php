<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Wildberries;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdRawDataParserInterface;

/**
 * Парсер rawPayload рекламной статистики Wildberries.
 *
 * TODO: уточнить реальный формат ответа advert-api.wildberries.ru.
 * Ожидаемая структура:
 * {
 *   "adverts": [
 *     {"advertId": 123, "advertName": "...", "nmId": 456,
 *      "sum": 150.50, "views": 1000, "clicks": 50}
 *   ]
 * }
 *
 * В WB nmId — родительский артикул, общий для всех размеров одного товара.
 */
final class WildberriesAdRawDataParser implements AdRawDataParserInterface
{
    public function supports(string $marketplace): bool
    {
        return $marketplace === MarketplaceType::WILDBERRIES->value;
    }

    /**
     * {@inheritdoc}
     */
    public function parse(string $rawPayload): array
    {
        $data = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $adverts = $data['adverts'] ?? [];
        if (!is_array($adverts) || $adverts === []) {
            return [];
        }

        /** @var array<string, array{campaignId: string, campaignName: string, parentSku: string, cost: string, impressions: int, clicks: int}> $aggregated */
        $aggregated = [];

        foreach ($adverts as $row) {
            if (!is_array($row)) {
                continue;
            }

            $campaignId = isset($row['advertId']) ? (string) $row['advertId'] : '';
            $parentSku  = isset($row['nmId']) ? (string) $row['nmId'] : '';
            if ($campaignId === '' || $parentSku === '') {
                continue;
            }

            $cost        = number_format((float) ($row['sum'] ?? 0), 2, '.', '');
            $impressions = (int) ($row['views'] ?? 0);
            $clicks      = (int) ($row['clicks'] ?? 0);
            $key         = $campaignId . '|' . $parentSku;

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'campaignId'   => $campaignId,
                    'campaignName' => isset($row['advertName']) ? (string) $row['advertName'] : '',
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
