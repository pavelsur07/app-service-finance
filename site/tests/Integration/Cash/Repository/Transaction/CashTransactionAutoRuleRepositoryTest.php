<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class CashTransactionAutoRuleRepositoryTest extends IntegrationTestCase
{
    public function testFindsOnlyActiveRulesInDeterministicPriorityOrder(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();

        $lowPriority = $this->createRule(
            '33333333-3333-3333-3333-333333333301',
            $company,
            $category,
            'Low priority',
            10,
        );
        $highPriority = $this->createRule(
            '33333333-3333-3333-3333-333333333302',
            $company,
            $category,
            'High priority',
            200,
        );
        $samePriority = $this->createRule(
            '33333333-3333-3333-3333-333333333304',
            $company,
            $category,
            'Same priority',
            200,
        );
        $inactive = $this->createRule(
            '33333333-3333-3333-3333-333333333303',
            $company,
            $category,
            'Inactive',
            999,
        )->setIsActive(false);

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($category);
        $this->em->persist($lowPriority);
        $this->em->persist($highPriority);
        $this->em->persist($samePriority);
        $this->em->persist($inactive);
        $this->em->flush();

        /** @var CashTransactionAutoRuleRepository $repository */
        $repository = $this->em->getRepository(CashTransactionAutoRule::class);

        self::assertSame(
            ['High priority', 'Same priority', 'Low priority'],
            array_map(static fn (CashTransactionAutoRule $rule): string => $rule->getName(), $repository->findActiveByCompany($company)),
        );
        self::assertSame(
            ['Inactive', 'High priority', 'Same priority', 'Low priority'],
            array_map(static fn (CashTransactionAutoRule $rule): string => $rule->getName(), $repository->findByCompany($company)),
        );
        self::assertSame(
            $highPriority->getId(),
            $repository->findOneByIdAndCompanyId((string) $highPriority->getId(), (string) $company->getId())?->getId(),
        );
        self::assertNull($repository->findOneByIdAndCompanyId(
            (string) $highPriority->getId(),
            '11111111-1111-1111-1111-999999999999',
        ));
    }

    public function testFindActiveEagerLoadsAssociationsUsedByMatching(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();
        $counterparty = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        $projectDirection = new ProjectDirection(
            '33333333-3333-3333-3333-333333333305',
            $company,
            'Project',
        );
        $rule = $this->createRule(
            '33333333-3333-3333-3333-333333333306',
            $company,
            $category,
            'Rule with associations',
            100,
        )
            ->setCounterparty($counterparty)
            ->setProjectDirection($projectDirection);
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::COUNTERPARTY,
            operator: CashTransactionAutoRuleConditionOperator::EQUAL,
            counterparty: $counterparty,
        ));

        foreach ([$user, $company, $category, $counterparty, $projectDirection, $rule] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $companyId = (string) $company->getId();
        $this->em->clear();

        /** @var CashTransactionAutoRuleRepository $repository */
        $repository = $this->em->getRepository(CashTransactionAutoRule::class);
        $loadedRule = $repository->findActiveByCompany($this->em->getReference(Company::class, $companyId))[0];

        self::assertFalse($this->em->isUninitializedObject($loadedRule->getCashflowCategory()));
        self::assertFalse($this->em->isUninitializedObject($loadedRule->getProjectDirection()));
        self::assertFalse($this->em->isUninitializedObject($loadedRule->getCounterparty()));
        self::assertFalse($this->em->isUninitializedObject($loadedRule->getConditions()->first()->getCounterparty()));
    }

    private function createRule(
        string $id,
        Company $company,
        CashflowCategory $category,
        string $name,
        int $priority,
    ): CashTransactionAutoRule {
        return (new CashTransactionAutoRule(
            $id,
            $company,
            $name,
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category,
        ))->setPriority($priority);
    }
}
