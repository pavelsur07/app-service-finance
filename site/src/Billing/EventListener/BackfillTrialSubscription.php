<?php

declare(strict_types=1);

namespace App\Billing\EventListener;

use App\Billing\Application\StartTrialAction;
use App\Company\Event\CompanyOnboardingBackfillEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Дозаполнение существующей компании: кабинет без подписки получает пробный период — тем же
 * правилом, что при создании (подписка есть → ничего).
 */
#[AsEventListener]
final readonly class BackfillTrialSubscription
{
    public function __construct(private StartTrialAction $startTrial)
    {
    }

    public function __invoke(CompanyOnboardingBackfillEvent $event): void
    {
        $event->report('billing_trial', ($this->startTrial)($event->companyId, dryRun: !$event->execute));
    }
}
