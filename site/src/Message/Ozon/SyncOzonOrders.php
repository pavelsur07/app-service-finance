<?php
namespace App\Message\Ozon;

final class SyncOzonOrders
{
    public function __construct(
        public readonly string $companyId,
        /** 'FBS' | 'FBO' */
        public readonly string $scheme,
        /** ISO8601 или null → возьмём из курсора */
        public readonly ?string $sinceIso = null,
        /** ISO8601 или null → now() */
        public readonly ?string $toIso = null,
        /** Только для FBS: необязательный фильтр статуса */
        public readonly ?string $status = null,
        /** Оверлап минут для надёжности, по умолчанию 10 */
        public readonly int $overlapMinutes = 10,
        /** TTL лок-блокировки в секундах, по умолчанию 55 минут */
        public readonly int $lockTtlSec = 3300,
    ) {}
}
