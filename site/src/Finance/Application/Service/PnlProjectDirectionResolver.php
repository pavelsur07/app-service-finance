<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Repository\ProjectDirectionRepository;
use App\Finance\Exception\PnlProjectDirectionResolveException;

final readonly class PnlProjectDirectionResolver
{
    public function __construct(private ProjectDirectionRepository $projectDirectionRepository)
    {
    }

    public function resolveDefault(Company $company): ProjectDirection
    {
        $default = $this->projectDirectionRepository->findDefaultForCompany($company);
        if ($default instanceof ProjectDirection) {
            return $default;
        }

        $roots = $this->projectDirectionRepository->findRootByCompany($company);
        if ([] !== $roots) {
            return $roots[0];
        }

        throw new PnlProjectDirectionResolveException(sprintf('Default project direction was not found for company "%s".', (string) $company->getId()));
    }
}
