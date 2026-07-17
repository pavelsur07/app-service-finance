<?php

declare(strict_types=1);

namespace App\Cash\Application\Service;

use App\Company\Application\DTO\FinancialResponsibilityCenterProjectDTO;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use Ramsey\Uuid\Uuid;

final readonly class CashTransactionResponsibilityCenterResolver
{
    private const UNAVAILABLE_PAIR_MESSAGE = 'Выбранная пара проекта и ЦФО недоступна.';

    public function __construct(
        private FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
    ) {
    }

    public function resolveForCreate(
        string $companyId,
        ?string $projectDirectionId,
        ?string $responsibilityCenterId,
    ): FinancialResponsibilityCenterProjectDTO {
        $this->assertCompanyId($companyId);

        if (null === $projectDirectionId && null === $responsibilityCenterId) {
            return $this->responsibilityCenterFacade->findGeneralPair($companyId)
                ?? throw new \DomainException('Системная пара проекта и ЦФО не настроена для компании.');
        }

        return $this->resolveExplicitPair($companyId, $projectDirectionId, $responsibilityCenterId);
    }

    public function resolveChangedPairForUpdate(
        string $companyId,
        ?string $currentProjectDirectionId,
        ?string $currentResponsibilityCenterId,
        ?string $submittedProjectDirectionId,
        ?string $submittedResponsibilityCenterId,
    ): ?FinancialResponsibilityCenterProjectDTO {
        $this->assertCompanyId($companyId);

        if ($currentProjectDirectionId === $submittedProjectDirectionId
            && $currentResponsibilityCenterId === $submittedResponsibilityCenterId) {
            return null;
        }

        return $this->resolveExplicitPair(
            $companyId,
            $submittedProjectDirectionId,
            $submittedResponsibilityCenterId,
        );
    }

    private function resolveExplicitPair(
        string $companyId,
        ?string $projectDirectionId,
        ?string $responsibilityCenterId,
    ): FinancialResponsibilityCenterProjectDTO {
        if (null === $projectDirectionId || null === $responsibilityCenterId) {
            throw new \DomainException('Укажите проект и ЦФО.');
        }

        if (!Uuid::isValid($projectDirectionId) || !Uuid::isValid($responsibilityCenterId)) {
            throw new \DomainException(self::UNAVAILABLE_PAIR_MESSAGE);
        }

        $center = $this->responsibilityCenterFacade->findByIdAndCompany($responsibilityCenterId, $companyId);
        if (null === $center || !$center->isActive()
            || !$this->responsibilityCenterFacade->isProjectAllowed(
                $companyId,
                $projectDirectionId,
                $responsibilityCenterId,
            )) {
            throw new \DomainException(self::UNAVAILABLE_PAIR_MESSAGE);
        }

        return new FinancialResponsibilityCenterProjectDTO($projectDirectionId, $responsibilityCenterId);
    }

    private function assertCompanyId(string $companyId): void
    {
        if (!Uuid::isValid($companyId)) {
            throw new \DomainException('Компания не найдена.');
        }
    }
}
