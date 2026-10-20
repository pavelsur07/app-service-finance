<?php

declare(strict_types=1);

namespace App\Finance\EventListener;

use App\Company\Event\CompanyCreatedEvent;
use App\Finance\Application\Action\EnsureDefaultPLTreeAction;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Базовое дерево ОПиУ новой компании. Событие диспатчится после flush внутри
 * транзакции создания: ошибка шаблона или импорта откатывает и компанию.
 */
#[AsEventListener]
final readonly class ApplyDefaultPLTreeOnCompanyCreated
{
    public function __construct(private EnsureDefaultPLTreeAction $ensure)
    {
    }

    public function __invoke(CompanyCreatedEvent $event): void
    {
        ($this->ensure)($event->companyId);
    }
}
