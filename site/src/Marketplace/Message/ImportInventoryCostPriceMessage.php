<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Запуск асинхронной обработки загруженного файла себестоимости.
 * Только scalar — безопасно для Worker/сериализации.
 */
final readonly class ImportInventoryCostPriceMessage
{
    public const SYSTEM_ACTOR_USER_ID = '00000000-0000-0000-0000-000000000001';

    public function __construct(
        public string $companyId,
        public string $storagePath,
        public string $originalFilename,
        public string $effectiveFrom,  // Y-m-d
        public string $marketplace,    // MarketplaceType::value
        public string $identifierType = 'barcode',
        public string $actorUserId = self::SYSTEM_ACTOR_USER_ID,
    ) {
    }
}
