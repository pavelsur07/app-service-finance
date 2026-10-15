<?php

declare(strict_types=1);

namespace App\Cash\EventListener;

use App\Cash\Application\EnsureCashflowSystemCategoriesAction;
use App\Company\Event\CompanyCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Системные статьи ДДС новой компании. Раньше их создавали `CompanyController::new`
 * и `CreateCompanyOwnerAccountAction`, обращаясь к сервису Cash напрямую; теперь Company
 * только сообщает о создании (этап 7.4 плана Cash). Оба пути диспатчат событие после
 * flush внутри транзакции, поэтому компания без статей не сохраняется.
 */
#[AsEventListener]
final readonly class EnsureCashflowSystemCategoriesOnCompanyCreated
{
    public function __construct(private EnsureCashflowSystemCategoriesAction $ensure)
    {
    }

    public function __invoke(CompanyCreatedEvent $event): void
    {
        ($this->ensure)($event->companyId);
    }
}
