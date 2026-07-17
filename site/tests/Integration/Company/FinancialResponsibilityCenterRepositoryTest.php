<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\FinancialResponsibilityCenterProjectRepository;
use App\Company\Repository\FinancialResponsibilityCenterRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class FinancialResponsibilityCenterRepositoryTest extends IntegrationTestCase
{
    public function testReadsAndAllowedPairsAreCompanyScoped(): void
    {
        $userA = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000401')
            ->withEmail('cfo-a@example.test')
            ->build();
        $userB = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000402')
            ->withEmail('cfo-b@example.test')
            ->build();
        $companyA = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-000000000401')
            ->withOwner($userA)
            ->build();
        $companyB = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-000000000402')
            ->withOwner($userB)
            ->build();
        $projectA = new ProjectDirection('33333333-3333-3333-3333-000000000401', $companyA, 'Project A');
        $projectB = new ProjectDirection('33333333-3333-3333-3333-000000000402', $companyB, 'Project B');
        $centerA = new FinancialResponsibilityCenter((string) $companyA->getId(), 'CFO_A', 'Center A');
        $centerB = new FinancialResponsibilityCenter((string) $companyB->getId(), 'CFO_B', 'Center B');
        $pairA = new FinancialResponsibilityCenterProject((string) $companyA->getId(), $projectA, $centerA);
        $pairB = new FinancialResponsibilityCenterProject((string) $companyB->getId(), $projectB, $centerB);

        foreach ([$userA, $userB, $companyA, $companyB, $projectA, $projectB, $centerA, $centerB, $pairA, $pairB] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        /** @var FinancialResponsibilityCenterRepository $centerRepository */
        $centerRepository = self::getContainer()->get(FinancialResponsibilityCenterRepository::class);
        /** @var FinancialResponsibilityCenterProjectRepository $pairRepository */
        $pairRepository = self::getContainer()->get(FinancialResponsibilityCenterProjectRepository::class);
        $facade = new FinancialResponsibilityCenterFacade($centerRepository, $pairRepository);

        self::assertSame([$centerA], $centerRepository->findActiveByCompanyId((string) $companyA->getId()));
        self::assertSame($centerA, $centerRepository->findOneByIdAndCompanyId($centerA->getId(), (string) $companyA->getId()));
        self::assertNull($centerRepository->findOneByIdAndCompanyId($centerA->getId(), (string) $companyB->getId()));
        self::assertTrue($pairRepository->isAllowed((string) $companyA->getId(), (string) $projectA->getId(), $centerA->getId()));
        self::assertFalse($pairRepository->isAllowed((string) $companyB->getId(), (string) $projectA->getId(), $centerA->getId()));
        self::assertSame([(string) $projectA->getId()], $pairRepository->findProjectIds((string) $companyA->getId(), $centerA->getId()));
        self::assertSame(1, $facade->findByIdAndCompany($centerA->getId(), (string) $companyA->getId())?->version);
    }
}
