<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Message;

/**
 * Асинхронное сообщение на скачивание готового отчёта Ozon Performance.
 *
 * Диспатчится {@see \App\MarketplaceAds\Application\Service\OzonAdReportPoller},
 * когда poll-cron наблюдает переход pending-отчёта в state=OK. Handler
 * {@see \App\MarketplaceAds\MessageHandler\DownloadOzonAdReportHandler}
 * забирает CSV из Ozon, делает upsert AdRawDocument за каждый день,
 * финализирует pending-отчёт и диспатчит ProcessAdRawDocumentMessage
 * за каждый созданный/обновлённый документ.
 *
 * Scalar-only payload (только companyId + pendingReportId): весь остальной
 * контекст (ozonUuid, dateFrom/dateTo, campaignIds, jobId) resolve'ится
 * по pendingReportId в БД. Это Messenger-safe (сериализуемо) и защищает
 * от расхождения сообщения с entity при retry.
 *
 * companyId дублируется в payload'е для IDOR defense-in-depth: handler
 * ре-проверяет, что загружаемый pending-отчёт действительно принадлежит
 * переданной компании.
 */
final readonly class DownloadOzonAdReportMessage
{
    public function __construct(
        public string $companyId,
        public string $pendingReportId,
    ) {
    }
}
