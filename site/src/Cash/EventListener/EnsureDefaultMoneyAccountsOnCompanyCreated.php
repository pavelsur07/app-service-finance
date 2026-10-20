<?php

declare(strict_types=1);

namespace App\Cash\EventListener;

use App\Cash\Application\EnsureDefaultMoneyAccountsAction;
use App\Company\Event\CompanyCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Денежные счета новой компании. Раньше «Основной счет» создавал
 * `CompanyOwnerMembershipCreator`, импортируя сущность Cash; теперь Company только
 * сообщает о создании. Событие диспатчится после flush внутри транзакции создания.
 */
#[AsEventListener]
final readonly class EnsureDefaultMoneyAccountsOnCompanyCreated
{
    public function __construct(private EnsureDefaultMoneyAccountsAction $ensure)
    {
    }

    public function __invoke(CompanyCreatedEvent $event): void
    {
        ($this->ensure)($event->companyId);
    }
}
