<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CashAiCategorizationRequestResult
{
    public function __construct(
        /** ИИ-сервис выключен для компании: ничего не поставлено. */
        public bool $enabled,
        /** Постановки без ответа дольше суток закрыты как потерянные. */
        public int $lost = 0,
        public int $requested = 0,
        public int $tasks = 0,
        /** Причина, по которой компания пропущена целиком (нет статей, справочник больше лимита). */
        public ?string $skippedReason = null,
    ) {
    }
}
