<?php

declare(strict_types=1);

namespace App\Company\Facade;

use App\Company\Entity\ConsolidationMembership;
use App\Company\Repository\ConsolidationMembershipRepository;

final class ConsolidationGroupFacade implements ConsolidationGroupFacadeInterface
{
    public function __construct(
        private readonly ConsolidationMembershipRepository $repository,
    ) {
    }

    public function headOf(string $companyId): ?string
    {
        return $this->repository->findOneByCompany($companyId)?->getHeadCompanyId();
    }

    public function lockedHeadOf(string $companyId): ?string
    {
        return $this->repository->lockedHeadOf($companyId);
    }

    public function members(string $headCompanyId): array
    {
        return array_map(
            static fn (ConsolidationMembership $row): string => $row->getCompanyId(),
            $this->repository->findGroupOfHead($headCompanyId),
        );
    }
}
