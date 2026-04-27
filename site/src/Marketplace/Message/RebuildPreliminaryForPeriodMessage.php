<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Асинхронное сообщение для пересборки предварительного ОПиУ за период.
 * Только scalar — безопасно для Worker/сериализации.
 */
final readonly class RebuildPreliminaryForPeriodMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplace,   // MarketplaceType::value
        public int    $year,
        public int    $month,
        public string $actorUserId,
    ) {
    }
}
