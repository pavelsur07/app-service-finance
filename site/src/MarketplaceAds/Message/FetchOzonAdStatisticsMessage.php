<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Message;

/**
 * Асинхронное сообщение на загрузку рекламной статистики Ozon Performance
 * за один чанк дат (≤ 62 дня — лимит API).
 *
 * Обрабатывается {@see \App\MarketplaceAds\MessageHandler\FetchOzonAdStatisticsHandler}:
 * запрос к OzonAdClient → upsert AdRawDocument за каждый день → атомарный
 * incrementLoadedDays связанного AdLoadJob → dispatch ProcessAdRawDocumentMessage
 * за каждый созданный/обновлённый документ.
 *
 * Диапазон передаётся в виде строк Y-m-d — только scalar-поля в Messenger
 * (правило CLAUDE.md для сериализуемости).
 */
final readonly class FetchOzonAdStatisticsMessage
{
    public function __construct(
        public string $jobId,
        public string $companyId,
        public string $dateFrom,
        public string $dateTo,
    ) {
    }
}
