<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CashAiCategorizationRequestResult
{
    public function __construct(
        /** ИИ-сервис выключен для компании: ничего не поставлено. */
        public bool $enabled,
        /** Строки без ответа дольше 7 дней закрыты как `failed/expired`. */
        public int $expired = 0,
        /** Задачи без ответа переотправлены с исходным ключом и payload. */
        public int $redispatched = 0,
        public int $requested = 0,
        public int $tasks = 0,
        /** Причина, по которой компания пропущена целиком (нет статей, справочник больше лимита). */
        public ?string $skippedReason = null,
    ) {
    }
}
