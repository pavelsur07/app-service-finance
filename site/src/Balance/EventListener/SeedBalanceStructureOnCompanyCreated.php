<?php

declare(strict_types=1);

namespace App\Balance\EventListener;

use App\Balance\Application\SeedBalanceStructureAction;
use App\Company\Event\CompanyCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class SeedBalanceStructureOnCompanyCreated
{
    public function __construct(private SeedBalanceStructureAction $seed)
    {
    }

    public function __invoke(CompanyCreatedEvent $event): void
    {
        ($this->seed)($event->companyId);
    }
}
