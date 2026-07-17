<?php

declare(strict_types=1);

namespace App\Cash\Application\Service;

use App\Company\Facade\FinancialResponsibilityCenterFacade;
use Ramsey\Uuid\Uuid;

final readonly class CashTransactionAutoRuleTargetValidator
{
    private const UNAVAILABLE_MESSAGE = 'Выбранный ЦФО недоступен для автоправила.';

    public function __construct(
        private FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
    ) {
    }

    public function assertValidChange(
        string $companyId,
        ?string $currentProjectDirectionId,
        ?string $currentResponsibilityCenterId,
        ?string $submittedProjectDirectionId,
        ?string $submittedResponsibilityCenterId,
    ): void {
        if ($currentProjectDirectionId === $submittedProjectDirectionId
            && $currentResponsibilityCenterId === $submittedResponsibilityCenterId) {
            return;
        }

        if (null !== $submittedProjectDirectionId && null === $submittedResponsibilityCenterId) {
            throw new \DomainException('Для проекта автоправила укажите ЦФО.');
        }

        if (null === $submittedResponsibilityCenterId) {
            return;
        }

        if (!Uuid::isValid($companyId) || !Uuid::isValid($submittedResponsibilityCenterId)) {
            throw new \DomainException(self::UNAVAILABLE_MESSAGE);
        }

        $center = $this->responsibilityCenterFacade->findByIdAndCompany(
            $submittedResponsibilityCenterId,
            $companyId,
        );
        if (null === $center || !$center->isActive()) {
            throw new \DomainException(self::UNAVAILABLE_MESSAGE);
        }

        if (null !== $submittedProjectDirectionId
            && (!Uuid::isValid($submittedProjectDirectionId)
                || !$this->responsibilityCenterFacade->isProjectAllowed(
                    $companyId,
                    $submittedProjectDirectionId,
                    $submittedResponsibilityCenterId,
                ))) {
            throw new \DomainException('Выбранная пара проекта и ЦФО недоступна для автоправила.');
        }
    }
}
