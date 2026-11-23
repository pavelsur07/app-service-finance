<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Facade;

use App\MarketplaceOzon\Application\DTO\OzonDayState;

/** Переводчик Ozon для других модулей: только чтение состояния перевода. */
interface MarketplaceOzonFacadeInterface
{
    /** @return list<OzonDayState> переведённые дни компании в окне дат */
    public function dayStates(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
