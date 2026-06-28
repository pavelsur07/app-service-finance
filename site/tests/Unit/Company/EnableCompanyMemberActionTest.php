<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Application\EnableCompanyMemberAction;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class EnableCompanyMemberActionTest extends TestCase
{
    public function testEnablesDisabledMember(): void
    {
        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $operator = UserBuilder::aUser()->withIndex(2)->withRoles(['ROLE_COMPANY_USER'])->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($operator)
            ->asDisabled()
            ->build();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $action = new EnableCompanyMemberAction(
            $this->companyRepositoryReturning($company),
            $this->memberRepositoryReturning($member),
            $entityManager,
        );

        $action((string) $company->getId(), (string) $member->getId(), $owner);

        self::assertSame(CompanyMember::STATUS_ACTIVE, $member->getStatus());
    }

    public function testRejectsNonOwnerActor(): void
    {
        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $actor = UserBuilder::aUser()->withIndex(2)->withRoles(['ROLE_COMPANY_USER'])->build();
        $operator = UserBuilder::aUser()->withIndex(3)->withRoles(['ROLE_COMPANY_USER'])->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $member = CompanyMemberBuilder::aMember()->withCompany($company)->withUser($operator)->asDisabled()->build();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $action = new EnableCompanyMemberAction(
            $this->companyRepositoryReturning($company),
            $this->memberRepositoryReturning($member),
            $entityManager,
        );

        $this->expectException(AccessDeniedException::class);

        $action((string) $company->getId(), (string) $member->getId(), $actor);
    }

    public function testRejectsMissingMember(): void
    {
        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $action = new EnableCompanyMemberAction(
            $this->companyRepositoryReturning($company),
            $this->memberRepositoryReturning(null),
            $entityManager,
        );

        $this->expectException(NotFoundHttpException::class);

        $action((string) $company->getId(), '44444444-4444-4444-4444-444444444444', $owner);
    }

    private function companyRepositoryReturning(?Company $company): CompanyRepository
    {
        $repository = $this->createMock(CompanyRepository::class);
        $repository
            ->method('findById')
            ->willReturn($company);

        return $repository;
    }

    private function memberRepositoryReturning(?CompanyMember $member): CompanyMemberRepository
    {
        $repository = $this->createMock(CompanyMemberRepository::class);
        $repository
            ->method('findOneByIdAndCompanyId')
            ->willReturn($member);

        return $repository;
    }
}
