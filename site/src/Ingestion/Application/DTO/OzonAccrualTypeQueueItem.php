<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Строка очереди «Новые типы Ozon»: тип из справочника, у которого есть
 * неразобранные начисления by-day (`known = false`), и предложение статьи.
 * `awaitingRefresh` — тип уже подтверждён (неразобранных внешних категорий нет),
 * строки ждут ночного refresh метаданных.
 */
final readonly class OzonAccrualTypeQueueItem
{
    public function __construct(
        public int $typeId,
        public string $name,
        public ?string $description,
        public bool $removed,
        public int $transactions,
        public string $firstDate,
        public string $lastDate,
        public string $transactionType,
        public OzonAccrualTypeProposal $proposal,
        public bool $awaitingRefresh = false,
    ) {
    }
}
