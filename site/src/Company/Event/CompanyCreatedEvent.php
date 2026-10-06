<?php

declare(strict_types=1);

namespace App\Company\Event;

/**
 * Компания создана и уже сохранена (flush выполнен): модули могут завести для неё
 * стартовые данные. Диспатчится синхронно, внутри транзакции создания, если она есть.
 */
final readonly class CompanyCreatedEvent
{
    public function __construct(public string $companyId)
    {
    }
}
