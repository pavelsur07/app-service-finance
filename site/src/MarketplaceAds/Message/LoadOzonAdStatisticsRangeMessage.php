<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Message;

/**
 * Верхний async-слой пайплайна загрузки рекламной статистики Ozon Performance.
 *
 * Обрабатывается {@see \App\MarketplaceAds\MessageHandler\LoadOzonAdStatisticsRangeHandler}:
 * читает AdLoadJob по jobId, бьёт диапазон dateFrom..dateTo на чанки ≤ 62 дня
 * (лимит Ozon API) и диспатчит N × {@see FetchOzonAdStatisticsMessage}.
 *
 * В сообщении единственное поле — jobId: companyId, dateFrom, dateTo лежат в
 * AdLoadJob и читаются handler'ом. AdLoadJob — единственный source of truth,
 * дублировать данные в Message смысла нет (а риск рассинхронизации — есть).
 */
final readonly class LoadOzonAdStatisticsRangeMessage
{
    public function __construct(
        public string $jobId,
    ) {
    }
}
