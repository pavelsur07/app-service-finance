<?php

declare(strict_types=1);

namespace App\Company\Facade;

use App\Company\Application\DTO\FinancialResponsibilityCenterDTO;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Repository\FinancialResponsibilityCenterProjectRepository;
use App\Company\Repository\FinancialResponsibilityCenterRepository;

final readonly class FinancialResponsibilityCenterFacade
{
    public function __construct(
        private FinancialResponsibilityCenterRepository $centerRepository,
        private FinancialResponsibilityCenterProjectRepository $projectRepository,
    ) {
    }

    /**
     * @return list<FinancialResponsibilityCenterDTO>
     */
    public function getActiveChoices(string $companyId): array
    {
        return \array_map(
            self::toDTO(...),
            $this->centerRepository->findActiveByCompanyId($companyId),
        );
    }

    public function findByIdAndCompany(string $id, string $companyId): ?FinancialResponsibilityCenterDTO
    {
        $center = $this->centerRepository->findOneByIdAndCompanyId($id, $companyId);

        return null === $center ? null : self::toDTO($center);
    }

    public function isProjectAllowed(
        string $companyId,
        string $projectDirectionId,
        string $responsibilityCenterId,
    ): bool {
        return $this->projectRepository->isAllowed(
            $companyId,
            $projectDirectionId,
            $responsibilityCenterId,
        );
    }

    /**
     * @return list<string>
     */
    public function getAllowedProjectIds(string $companyId, string $responsibilityCenterId): array
    {
        return $this->projectRepository->findProjectIds($companyId, $responsibilityCenterId);
    }

    private static function toDTO(FinancialResponsibilityCenter $center): FinancialResponsibilityCenterDTO
    {
        return new FinancialResponsibilityCenterDTO(
            id: $center->getId(),
            code: $center->getCode(),
            name: $center->getName(),
            sort: $center->getSort(),
            status: $center->getStatus()->value,
            system: $center->isSystem(),
            version: $center->getVersion(),
        );
    }
}
