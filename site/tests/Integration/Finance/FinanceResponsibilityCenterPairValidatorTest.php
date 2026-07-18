<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\FinancialResponsibilityCenterProjectRepository;
use App\Company\Repository\FinancialResponsibilityCenterRepository;
use App\Finance\Application\Service\FinanceResponsibilityCenterPairValidator;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class FinanceResponsibilityCenterPairValidatorTest extends IntegrationTestCase
{
    private FinanceResponsibilityCenterPairValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new FinanceResponsibilityCenterPairValidator(new FinancialResponsibilityCenterFacade(
            self::getContainer()->get(FinancialResponsibilityCenterRepository::class),
            self::getContainer()->get(FinancialResponsibilityCenterProjectRepository::class),
        ));
    }

    public function testFinanceResponsibilityCenterScalarMappingsRoundTrip(): void
    {
        $graph = $this->createCompanyGraph(7711);
        $category = new PLCategory('99999999-9999-9999-9999-000000007711', $graph['company']);
        $category->setName('Stage 7.7.1 category');

        $document = new Document('66666666-6666-6666-6666-000000007711', $graph['company']);
        $document
            ->setProjectDirection($graph['customProject'])
            ->setResponsibilityCenterId($graph['customCenter']->getId());

        $operation = new DocumentOperation('77777777-7777-7777-7777-000000007711');
        $operation
            ->setAmount('123.45')
            ->setProjectDirection($graph['customProject'])
            ->setResponsibilityCenterId($graph['customCenter']->getId());
        $document->addOperation($operation);

        $dailyTotal = new PLDailyTotal(
            '88888888-8888-8888-8888-000000007711',
            $graph['company'],
            $graph['customProject'],
            new \DateTimeImmutable('2026-07-18'),
            $category,
        );
        $dailyTotal->setResponsibilityCenterId($graph['customCenter']->getId());

        foreach ([$category, $document, $dailyTotal] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $reloadedDocument = $this->em->find(Document::class, $document->getId());
        self::assertInstanceOf(Document::class, $reloadedDocument);
        self::assertSame($graph['customCenter']->getId(), $reloadedDocument->getResponsibilityCenterId());

        $reloadedOperation = $this->em->find(DocumentOperation::class, $operation->getId());
        self::assertInstanceOf(DocumentOperation::class, $reloadedOperation);
        self::assertSame($graph['customCenter']->getId(), $reloadedOperation->getResponsibilityCenterId());

        $reloadedDailyTotal = $this->em->find(PLDailyTotal::class, $dailyTotal->getId());
        self::assertInstanceOf(PLDailyTotal::class, $reloadedDailyTotal);
        self::assertSame($graph['customCenter']->getId(), $reloadedDailyTotal->getResponsibilityCenterId());
    }

    public function testValidatorAllowsNullAndSameCompanyActiveAllowedPair(): void
    {
        $graph = $this->createCompanyGraph(7712);
        $this->em->flush();

        $this->validator->assertValidNullablePair($graph['company']->getId(), null, null);
        $this->validator->assertValidNullablePair(
            $graph['company']->getId(),
            $graph['customProject']->getId(),
            $graph['customCenter']->getId(),
        );

        self::addToAssertionCount(2);
    }

    public function testValidatorRejectsIncompleteMalformedCrossCompanyDisallowedAndArchivedPairs(): void
    {
        $companyA = $this->createCompanyGraph(7713);
        $companyB = $this->createCompanyGraph(7714);
        $this->em->flush();

        $cases = [
            fn () => $this->validator->assertValidNullablePair(
                'not-a-company-uuid',
                null,
                null,
            ),
            fn () => $this->validator->assertValidNullablePair(
                $companyA['company']->getId(),
                null,
                $companyA['customCenter']->getId(),
            ),
            fn () => $this->validator->assertValidNullablePair(
                $companyA['company']->getId(),
                'not-a-uuid',
                $companyA['customCenter']->getId(),
            ),
            fn () => $this->validator->assertValidNullablePair(
                $companyA['company']->getId(),
                $companyB['customProject']->getId(),
                $companyA['customCenter']->getId(),
            ),
            fn () => $this->validator->assertValidNullablePair(
                $companyA['company']->getId(),
                $companyA['systemProject']->getId(),
                $companyA['customCenter']->getId(),
            ),
        ];

        foreach ($cases as $case) {
            $this->assertDomainException($case);
        }

        $companyA['customCenter']->archive();
        $this->em->flush();

        $this->assertDomainException(fn () => $this->validator->assertValidNullablePair(
            $companyA['company']->getId(),
            $companyA['customProject']->getId(),
            $companyA['customCenter']->getId(),
        ));
    }

    /**
     * @return array{
     *     company: Company,
     *     systemProject: ProjectDirection,
     *     customProject: ProjectDirection,
     *     systemCenter: FinancialResponsibilityCenter,
     *     customCenter: FinancialResponsibilityCenter
     * }
     */
    private function createCompanyGraph(int $index): array
    {
        $user = UserBuilder::aUser()
            ->withId(sprintf('22222222-2222-2222-2222-%012d', $index))
            ->withEmail(sprintf('stage-7-7-%d@example.test', $index))
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(sprintf('11111111-1111-1111-1111-%012d', $index))
            ->withOwner($user)
            ->build();
        $systemProject = new ProjectDirection(
            sprintf('33333333-3333-3333-3333-%012d', $index),
            $company,
            'Общий',
            ProjectDirection::CODE_GENERAL,
        );
        $customProject = new ProjectDirection(
            sprintf('44444444-4444-4444-4444-%012d', $index),
            $company,
            'Продажа компьютеров',
        );
        $systemCenter = new FinancialResponsibilityCenter(
            $company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );
        $customCenter = new FinancialResponsibilityCenter(
            $company->getId(),
            sprintf('CFO_%d', $index),
            'Краснодар',
        );

        foreach ([$user, $company, $systemProject, $customProject, $systemCenter, $customCenter] as $entity) {
            $this->em->persist($entity);
        }

        $this->em->persist(new FinancialResponsibilityCenterProject(
            $company->getId(),
            $systemProject,
            $systemCenter,
        ));
        $this->em->persist(new FinancialResponsibilityCenterProject(
            $company->getId(),
            $customProject,
            $customCenter,
        ));

        return [
            'company' => $company,
            'systemProject' => $systemProject,
            'customProject' => $customProject,
            'systemCenter' => $systemCenter,
            'customCenter' => $customCenter,
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
