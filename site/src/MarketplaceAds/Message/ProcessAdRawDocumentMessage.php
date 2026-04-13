<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Message;

/**
 * Асинхронное сообщение на обработку AdRawDocument:
 * парсинг payload → распределение затрат → создание AdDocument + AdDocumentLine.
 *
 * Обрабатывается {@see \App\MarketplaceAds\MessageHandler\ProcessAdRawDocumentHandler}.
 */
final readonly class ProcessAdRawDocumentMessage
{
    public function __construct(
        public string $companyId,
        public string $adRawDocumentId,
    ) {
    }
}
