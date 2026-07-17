<?php

declare(strict_types=1);

namespace App\Company\Application\Service;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
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

        $generalProject = new ProjectDirection(
            id: Uuid::uuid4()->toString(),
            company: $company,
            name: 'Общий',
            systemCode: ProjectDirection::CODE_GENERAL,
        );
        $generalCenter = new FinancialResponsibilityCenter(
            companyId: (string) $company->getId(),
            code: FinancialResponsibilityCenter::CODE_GENERAL,
            name: FinancialResponsibilityCenter::NAME_GENERAL,
        );

        $this->entityManager->persist($company);
        $this->entityManager->persist(new CompanyMember(
            id: Uuid::uuid4()->toString(),
            company: $company,
            user: $owner,
            role: CompanyMember::ROLE_OWNER,
        ));
        $this->entityManager->persist($generalProject);
        $this->entityManager->persist($generalCenter);
        $this->entityManager->persist(new FinancialResponsibilityCenterProject(
            companyId: (string) $company->getId(),
            projectDirection: $generalProject,
            responsibilityCenter: $generalCenter,
        ));
        $this->entityManager->persist(new MoneyAccount(
            id: Uuid::uuid4()->toString(),
            company: $company,
            type: MoneyAccountType::BANK,
            name: 'Основной счет',
            currency: 'RUB',
        ));

        return $company;
    }
}
