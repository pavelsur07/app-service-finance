<?php

declare(strict_types=1);

namespace App\Balance\EventListener;

use App\Balance\Application\EnsureBalanceAccountsAction;
use App\Balance\Application\SeedBalanceStructureAction;
use App\Company\Event\CompanyCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Стартовая структура Баланса новой компании по её профилю: статьи шаблона, затем базовые учётные счета
 * на них. Книга не инициализируется — дату начала и остатки задаёт пользователь.
 */
#[AsEventListener]
final readonly class SeedBalanceStructureOnCompanyCreated
{
    public function __construct(
        private SeedBalanceStructureAction $seed,
        private EnsureBalanceAccountsAction $accounts,
    ) {
    }

    public function __invoke(CompanyCreatedEvent $event): void
    {
        ($this->seed)($event->companyId, profile: $event->businessProfile);
        ($this->accounts)($event->companyId, profile: $event->businessProfile);
    }
}
