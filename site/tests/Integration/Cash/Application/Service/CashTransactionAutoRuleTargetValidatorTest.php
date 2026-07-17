<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application\Service;

use App\Cash\Application\Service\CashTransactionAutoRuleTargetValidator;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\FinancialResponsibilityCenterProjectRepository;
use App\Company\Repository\FinancialResponsibilityCenterRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class CashTransactionAutoRuleTargetValidatorTest extends IntegrationTestCase
{
    private CashTransactionAutoRuleTargetValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new CashTransactionAutoRuleTargetValidator(new FinancialResponsibilityCenterFacade(
            self::getContainer()->get(FinancialResponsibilityCenterRepository::class),
            self::getContainer()->get(FinancialResponsibilityCenterProjectRepository::class),
        ));
    }

    public function testAcceptsAllowedCompleteAndActiveCenterOnlyTargets(): void
    {
        $graph = $this->createCompanyGraph(7911);
        $this->em->flush();

        $this->validator->assertValidChange(
            $graph['companyId'],
            null,
            null,
            $graph['project']->getId(),
            $graph['center']->getId(),
        );
        $this->validator->assertValidChange(
            $graph['companyId'],
            null,
            null,
            null,
            $graph['center']->getId(),
        );

        self::addToAssertionCount(2);
    }

    public function testPreservesUnchangedLegacyProjectOnlyTarget(): void
    {
        $graph = $this->createCompanyGraph(7912);

        $this->validator->assertValidChange(
            $graph['companyId'],
            $graph['project']->getId(),
            null,
            $graph['project']->getId(),
            null,
        );

        self::addToAssertionCount(1);
    }

    public function testRejectsNewProjectOnlyMalformedCrossCompanyAndDisallowedTargets(): void
    {
        $companyA = $this->createCompanyGraph(7913);
        $companyB = $this->createCompanyGraph(7914);
        $this->em->flush();

        $cases = [
            fn () => $this->validator->assertValidChange(
                $companyA['companyId'],
                null,
                null,
                $companyA['project']->getId(),
                null,
            ),
            fn () => $this->validator->assertValidChange(
                $companyA['companyId'],
                null,
                null,
                'not-a-uuid',
                $companyA['center']->getId(),
            ),
            fn () => $this->validator->assertValidChange(
                $companyA['companyId'],
                null,
                null,
                null,
                'not-a-uuid',
            ),
            fn () => $this->validator->assertValidChange(
                $companyA['companyId'],
                null,
                null,
                $companyA['project']->getId(),
                $companyB['center']->getId(),
            ),
            fn () => $this->validator->assertValidChange(
                $companyA['companyId'],
                null,
                null,
                $companyA['otherProject']->getId(),
                $companyA['center']->getId(),
            ),
        ];

        foreach ($cases as $case) {
            $this->assertDomainException($case);
        }
    }

    public function testRejectsArchivedTargetWhenClassificationChanges(): void
    {
        $graph = $this->createCompanyGraph(7915);
        $this->em->flush();
        $graph['center']->archive();
        $this->em->flush();

        $this->validator->assertValidChange(
            $graph['companyId'],
            $graph['project']->getId(),
            $graph['center']->getId(),
            $graph['project']->getId(),
            $graph['center']->getId(),
        );
        $this->assertDomainException(fn () => $this->validator->assertValidChange(
            $graph['companyId'],
            null,
            null,
            $graph['project']->getId(),
            $graph['center']->getId(),
        ));
        self::addToAssertionCount(1);
    }

    /**
     * @return array{
     *     companyId: string,
     *     project: ProjectDirection,
     *     otherProject: ProjectDirection,
     *     center: FinancialResponsibilityCenter
     * }
     */
    private function createCompanyGraph(int $index): array
    {
        $user = UserBuilder::aUser()
            ->withId(sprintf('22222222-2222-2222-2222-%012d', $index))
            ->withEmail(sprintf('stage-7-9-%d@example.test', $index))
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(sprintf('11111111-1111-1111-1111-%012d', $index))
            ->withOwner($user)
            ->build();
        $project = new ProjectDirection(
            sprintf('33333333-3333-3333-3333-%012d', $index),
            $company,
            'Продажа компьютеров',
        );
        $otherProject = new ProjectDirection(
            sprintf('44444444-4444-4444-4444-%012d', $index),
            $company,
            'Сервисные услуги',
        );
        $center = new FinancialResponsibilityCenter(
            $company->getId(),
            sprintf('CFO_%d', $index),
            'Краснодар',
        );

        foreach ([$user, $company, $project, $otherProject, $center] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new FinancialResponsibilityCenterProject(
            $company->getId(),
            $project,
            $center,
        ));

        return [
            'companyId' => $company->getId(),
            'project' => $project,
            'otherProject' => $otherProject,
            'center' => $center,
        ];
    }

    private function assertDomainException(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Expected DomainException was not thrown.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
    }
}
