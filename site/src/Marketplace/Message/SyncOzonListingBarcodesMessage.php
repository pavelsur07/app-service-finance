<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Запуск синхронизации баркодов листингов Ozon.
 * Только scalar — безопасно для Worker/сериализации.
 */
final readonly class SyncOzonListingBarcodesMessage
{
    public function __construct(
        public string $companyId,
    ) {
    }
}
