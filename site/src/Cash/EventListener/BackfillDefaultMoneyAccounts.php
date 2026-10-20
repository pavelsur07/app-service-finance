<?php

declare(strict_types=1);

namespace App\Cash\EventListener;

use App\Cash\Application\EnsureDefaultMoneyAccountsAction;
use App\Company\Event\CompanyOnboardingBackfillEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Дозаполнение существующей компании: «Основной счет» и касса «Основная» — тем же
 * правилом, что при создании (нет счёта этого типа → создать).
 */
#[AsEventListener]
final readonly class BackfillDefaultMoneyAccounts
{
    public function __construct(private EnsureDefaultMoneyAccountsAction $ensure)
    {
    }

    public function __invoke(CompanyOnboardingBackfillEvent $event): void
    {
        $event->report('money_accounts', ($this->ensure)($event->companyId, dryRun: !$event->execute));
    }
}
