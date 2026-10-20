<?php

declare(strict_types=1);

namespace App\Balance\EventListener;

use App\Balance\Application\EnsureBalanceAccountsAction;
use App\Balance\Application\EnsureBalanceStructureAction;
use App\Balance\Infrastructure\Query\CompaniesWithoutBalanceStructureQuery;
use App\Company\Event\CompanyOnboardingBackfillEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Дозаполнение существующей компании: нет структуры Баланса — структура и базовые счета
 * (как ленивый сид), структура есть — только счета, если у компании нет ни одного.
 */
#[AsEventListener]
final readonly class BackfillBalanceSetup
{
    public function __construct(
        private CompaniesWithoutBalanceStructureQuery $missingStructure,
        private EnsureBalanceStructureAction $structure,
        private EnsureBalanceAccountsAction $accounts,
    ) {
    }

    public function __invoke(CompanyOnboardingBackfillEvent $event): void
    {
        if ([] !== ($this->missingStructure)([$event->companyId])) {
            // Структура создаётся вместе со счетами шаблона — в отчёте оба пункта.
            $event->report('balance_structure', 1);
            $event->report('balance_accounts', $this->accounts->templateAccountCount($event->businessProfile));
            if ($event->execute) {
                ($this->structure)($event->companyId, $event->businessProfile);
            }

            return;
        }

        $event->report('balance_accounts', ($this->accounts)($event->companyId, dryRun: !$event->execute, profile: $event->businessProfile));
    }
}
