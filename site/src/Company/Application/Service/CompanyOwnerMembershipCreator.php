<?php

declare(strict_types=1);

namespace App\Company\Application\Service;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class CompanyOwnerMembershipCreator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function createCompany(User $owner, string $companyName): Company
    {
        $company = new Company(Uuid::uuid4()->toString(), $owner);
        $company->setName(\trim($companyName));

        return $this->persistCompanyWithOwnerMembership($company, $owner);
    }

    public function persistCompanyWithOwnerMembership(Company $company, User $owner): Company
    {
        $company->setUser($owner);
        $owner->addCompany($company);

        $this->entityManager->persist($company);
        $this->entityManager->persist(new CompanyMember(
            id: Uuid::uuid4()->toString(),
            company: $company,
            user: $owner,
            role: CompanyMember::ROLE_OWNER,
        ));

        return $company;
    }
}
