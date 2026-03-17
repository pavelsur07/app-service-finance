<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Асинхронное сообщение для закрытия этапа месяца.
 * Только scalar — безопасно для Worker/сериализации.
 */
final readonly class CloseMonthStageMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplace,
        public int    $year,
        public int    $month,
        public string $stage,        // CloseStage::value
        public string $actorUserId,
    ) {
    }
}
