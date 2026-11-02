<?php

declare(strict_types=1);

namespace App\Billing\EventListener;

use App\Billing\Application\StartTrialAction;
use App\Company\Event\CompanyCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Новый кабинет сразу получает пробный период. Событие диспатчится внутри транзакции
 * создания компании — подписка коммитится вместе с ней.
 */
#[AsEventListener]
final readonly class StartTrialOnCompanyCreated
{
    public function __construct(private StartTrialAction $startTrial)
    {
    }

    public function __invoke(CompanyCreatedEvent $event): void
    {
        ($this->startTrial)($event->companyId);
    }
}
