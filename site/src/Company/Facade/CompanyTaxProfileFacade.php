<?php

declare(strict_types=1);

namespace App\Company\Facade;

use App\Company\Facade\DTO\CompanyTaxProfileDTO;
use App\Company\Repository\CompanyTaxProfileRepository;

final class CompanyTaxProfileFacade implements CompanyTaxProfileFacadeInterface
{
    public function __construct(
        private readonly CompanyTaxProfileRepository $repository,
    ) {
    }

    public function taxProfileOn(string $companyId, \DateTimeImmutable $date): ?CompanyTaxProfileDTO
    {
        $profile = $this->repository->findEffectiveOn($companyId, $date);

        return null === $profile
            ? null
            : new CompanyTaxProfileDTO($profile->getTaxRegime(), $profile->getVatMode(), $profile->getValidFrom());
    }
}
