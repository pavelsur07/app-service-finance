<?php

declare(strict_types=1);

namespace App\Finance\EventListener;

use App\Company\Event\CompanyOnboardingBackfillEvent;
use App\Finance\Application\Action\EnsureDefaultPLTreeAction;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Дозаполнение существующей компании: базовое дерево ОПиУ, только если у неё нет ни одной статьи.
 */
#[AsEventListener]
final readonly class BackfillDefaultPLTree
{
    public function __construct(private EnsureDefaultPLTreeAction $ensure)
    {
    }

    public function __invoke(CompanyOnboardingBackfillEvent $event): void
    {
        $event->report('pl_categories', ($this->ensure)($event->companyId, dryRun: !$event->execute));
    }
}
