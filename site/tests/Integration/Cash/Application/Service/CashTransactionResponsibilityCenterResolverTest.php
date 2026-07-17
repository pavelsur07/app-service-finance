<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application\Service;

use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\FinancialResponsibilityCenterProjectRepository;
use App\Company\Repository\FinancialResponsibilityCenterRepository;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class CashTransactionResponsibilityCenterResolverTest extends IntegrationTestCase
{
    private CashTransactionResponsibilityCenterResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new CashTransactionResponsibilityCenterResolver(new FinancialResponsibilityCenterFacade(
            self::getContainer()->get(FinancialResponsibilityCenterRepository::class),
            self::getContainer()->get(FinancialResponsibilityCenterProjectRepository::class),
        ));
    }

    public function testResolvesSystemAndExplicitCompanyPairs(): void
    {
        $graph = $this->createCompanyGraph(7611);
        $this->em->flush();

        $systemPair = $this->resolver->resolveForCreate($graph['company']->getId(), null, null);
        self::assertSame($graph['systemProject']->getId(), $systemPair->projectDirectionId);
        self::assertSame($graph['systemCenter']->getId(), $systemPair->responsibilityCenterId);

        $explicitPair = $this->resolver->resolveForCreate(
            $graph['company']->getId(),
            $graph['customProject']->getId(),
            $graph['customCenter']->getId(),
        );
        self::assertSame($graph['customProject']->getId(), $explicitPair->projectDirectionId);
        self::assertSame($graph['customCenter']->getId(), $explicitPair->responsibilityCenterId);
    }

    public function testRejectsPartialMalformedCrossCompanyDisallowedAndArchivedPairs(): void
    {
        $companyA = $this->createCompanyGraph(7612);
        $companyB = $this->createCompanyGraph(7613);
        $this->em->flush();

        $cases = [
            fn () => $this->resolver->resolveForCreate(
                $companyA['company']->getId(),
                $companyA['customProject']->getId(),
                null,
            ),
            fn () => $this->resolver->resolveForCreate(
                $companyA['company']->getId(),
                'not-a-uuid',
                $companyA['customCenter']->getId(),
            ),
            fn () => $this->resolver->resolveForCreate(
                $companyA['company']->getId(),
                $companyB['customProject']->getId(),
                $companyA['customCenter']->getId(),
            ),
            fn () => $this->resolver->resolveForCreate(
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

        $this->assertDomainException(fn () => $this->resolver->resolveForCreate(
            $companyA['company']->getId(),
            $companyA['customProject']->getId(),
            $companyA['customCenter']->getId(),
        ));
    }

    public function testUpdatePreservesUnchangedLegacyTupleAndValidatesChanges(): void
    {
        $graph = $this->createCompanyGraph(7614);
        $this->em->flush();

        self::assertNull($this->resolver->resolveChangedPairForUpdate(
            $graph['company']->getId(),
            null,
            null,
            null,
            null,
        ));
        self::assertNull($this->resolver->resolveChangedPairForUpdate(
            $graph['company']->getId(),
            $graph['customProject']->getId(),
            null,
            $graph['customProject']->getId(),
            null,
        ));

        $changedPair = $this->resolver->resolveChangedPairForUpdate(
            $graph['company']->getId(),
            null,
            null,
            $graph['customProject']->getId(),
            $graph['customCenter']->getId(),
        );
        self::assertNotNull($changedPair);
        self::assertSame($graph['customProject']->getId(), $changedPair->projectDirectionId);
        self::assertSame($graph['customCenter']->getId(), $changedPair->responsibilityCenterId);

        $this->assertDomainException(fn () => $this->resolver->resolveChangedPairForUpdate(
            $graph['company']->getId(),
            $graph['customProject']->getId(),
            $graph['customCenter']->getId(),
            null,
            null,
        ));
    }

    public function testFailsClosedWhenSystemPairIsMissing(): void
    {
        $graph = $this->createCompanyGraph(7615, false);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Системная пара проекта и ЦФО не настроена для компании.');

        $this->resolver->resolveForCreate($graph['company']->getId(), null, null);
    }

    public function testCashTransactionScalarMappingRoundTrips(): void
    {
        $graph = $this->createCompanyGraph(7616);
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId('55555555-5555-5555-5555-000000007616')
            ->forCompany($graph['company'])
            ->build();
        $transaction = new CashTransaction(
            '66666666-6666-6666-6666-000000007616',
            $graph['company'],
            $account,
            CashDirection::OUTFLOW,
            '100.00',
            'RUB',
            new \DateTimeImmutable('2026-07-17'),
        );
        $transaction
            ->setProjectDirection($graph['customProject'])
            ->setResponsibilityCenterId($graph['customCenter']->getId());

        $this->em->persist($account);
        $this->em->persist($transaction);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(CashTransaction::class, $transaction->getId());
        self::assertInstanceOf(CashTransaction::class, $reloaded);
        self::assertSame($graph['customCenter']->getId(), $reloaded->getResponsibilityCenterId());
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
    private function createCompanyGraph(int $index, bool $withSystemPair = true): array
    {
        $user = UserBuilder::aUser()
            ->withId(sprintf('22222222-2222-2222-2222-%012d', $index))
            ->withEmail(sprintf('stage-7-6-%d@example.test', $index))
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
        if ($withSystemPair) {
            $this->em->persist(new FinancialResponsibilityCenterProject(
                $company->getId(),
                $systemProject,
                $systemCenter,
            ));
        }
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
