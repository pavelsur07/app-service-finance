<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Wildberries;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdRawDataParserInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
        $skippedNonArray = 0;
        $skippedMissingFields = 0;

        foreach ($adverts as $index => $row) {
            if (!is_array($row)) {
                ++$skippedNonArray;
                continue;
            }

            $campaignId = isset($row['advertId']) ? (string) $row['advertId'] : '';
            $parentSku  = isset($row['nmId']) ? (string) $row['nmId'] : '';
            if ($campaignId === '' || $parentSku === '') {
                ++$skippedMissingFields;
                $this->logger->warning(
                    'Wildberries ad raw row skipped: missing required fields advertId/nmId',
                    [
                        'index'           => $index,
                        'has_advert_id'   => $campaignId !== '',
                        'has_nm_id'       => $parentSku !== '',
                        'marketplace'     => MarketplaceType::WILDBERRIES->value,
                    ],
                );
                continue;
            }

            $cost        = number_format((float) ($row['sum'] ?? 0), self::AGGREGATION_SCALE, '.', '');
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

            $aggregated[$key]['cost']        = bcadd($aggregated[$key]['cost'], $cost, self::AGGREGATION_SCALE);
            $aggregated[$key]['impressions'] += $impressions;
            $aggregated[$key]['clicks']      += $clicks;
        }

        if ($skippedNonArray > 0 || $skippedMissingFields > 0) {
            $this->logger->info(
                'Wildberries ad raw payload: some rows were skipped during parsing',
                [
                    'total_rows'             => count($adverts),
                    'skipped_non_array'      => $skippedNonArray,
                    'skipped_missing_fields' => $skippedMissingFields,
                    'aggregated_entries'     => count($aggregated),
                    'marketplace'            => MarketplaceType::WILDBERRIES->value,
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
