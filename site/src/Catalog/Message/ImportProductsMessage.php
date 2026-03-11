<?php

declare(strict_types=1);

namespace App\Catalog\Message;

/**
 * Сообщение для асинхронной обработки импорта товаров через Messenger.
 *
 * companyId передаётся как string — ActiveCompanyService в Handler/Worker недоступен
 * (нет HTTP-сессии). Согласно правилам разработки.
 */
final class ImportProductsMessage
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $importId,
    ) {
    }
}
