<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Facade;

use App\MarketplaceWb\Application\DTO\WbDayState;

/** Переводчик WB для других модулей: только чтение состояния перевода. */
interface MarketplaceWbFacadeInterface
{
    /** @return list<WbDayState> переведённые дни компании в окне дат */
    public function dayStates(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
