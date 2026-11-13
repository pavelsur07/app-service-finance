<?php

declare(strict_types=1);

namespace App\Cash\EventListener;

use App\Cash\Application\Category\ApplyCashflowCoreTemplateAction;
use App\Company\Event\CompanyCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ядро статей ДДС новой компании. Priority ниже, чем у EnsureCashflowSystemCategoriesOnCompanyCreated
 * (0): статьи встают под системные группы деятельности, которые тот уже записал. Backfill
 * онбординга (CompanyOnboardingBackfillEvent) ядро не применяет — существующие компании не меняются.
 */
#[AsEventListener(priority: -10)]
final readonly class ApplyCashflowCoreTemplateOnCompanyCreated
{
    public function __construct(private ApplyCashflowCoreTemplateAction $apply)
    {
    }

    public function __invoke(CompanyCreatedEvent $event): void
    {
        ($this->apply)($event->companyId);
    }
}
