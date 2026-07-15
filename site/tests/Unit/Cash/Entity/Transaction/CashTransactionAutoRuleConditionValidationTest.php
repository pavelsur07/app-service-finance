<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Entity\Transaction;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CashTransactionAutoRuleConditionValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testAcceptsLocalizedAmountRange(): void
    {
        $condition = new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::AMOUNT,
            operator: CashTransactionAutoRuleConditionOperator::BETWEEN,
            value: '100,50',
            valueTo: '200,00',
        );

        self::assertCount(0, $this->validator->validate($condition));
    }

    public function testRejectsConditionWithoutFieldAndOperator(): void
    {
        $violations = $this->validator->validate(new CashTransactionAutoRuleCondition());

        self::assertCount(2, $violations);
        self::assertContains('field', $this->paths($violations));
        self::assertContains('operator', $this->paths($violations));
    }

    public function testRejectsUnsupportedDateOperator(): void
    {
        $condition = new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::DATE,
            operator: CashTransactionAutoRuleConditionOperator::GREATER_THAN,
            value: '2026-07-15',
        );

        self::assertContains('operator', $this->paths($this->validator->validate($condition)));
    }

    public function testRejectsReversedAmountRange(): void
    {
        $condition = new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::AMOUNT,
            operator: CashTransactionAutoRuleConditionOperator::BETWEEN,
            value: '200',
            valueTo: '100',
        );

        self::assertContains('valueTo', $this->paths($this->validator->validate($condition)));
    }

    public function testRejectsInvalidInn(): void
    {
        $condition = new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::INN,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: '12345',
        );

        self::assertContains('value', $this->paths($this->validator->validate($condition)));
    }

    public function testRuleRequiresAtLeastOneCondition(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Аренда',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::OUTFLOW,
            CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build(),
        );

        self::assertContains('conditions', $this->paths($this->validator->validate($rule)));
    }

    public function testRuleRejectsTargetsFromAnotherCompany(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->build();
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Аренда',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::OUTFLOW,
            CashflowCategoryBuilder::aCashflowCategory()->withCompany($otherCompany)->build(),
            CounterpartyBuilder::aCounterparty()->withCompany($otherCompany)->build(),
        );
        $rule->setProjectDirection(new ProjectDirection(Uuid::uuid4()->toString(), $otherCompany, 'Чужой проект'));

        $paths = $this->paths($this->validator->validate($rule));

        self::assertContains('cashflowCategory', $paths);
        self::assertContains('projectDirection', $paths);
        self::assertContains('counterparty', $paths);
    }

    public function testConditionRejectsCounterpartyFromAnotherCompany(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->build();
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Аренда',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::OUTFLOW,
            CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build(),
        );
        $condition = new CashTransactionAutoRuleCondition(
            autoRule: $rule,
            field: CashTransactionAutoRuleConditionField::COUNTERPARTY,
            operator: CashTransactionAutoRuleConditionOperator::EQUAL,
            counterparty: CounterpartyBuilder::aCounterparty()->withCompany($otherCompany)->build(),
        );

        self::assertContains('counterparty', $this->paths($this->validator->validate($condition)));
    }

    public function testRuleCompanyCannotBeReassigned(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Аренда',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::OUTFLOW,
            CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $rule->setCompany(CompanyBuilder::aCompany()->withIndex(2)->build());
    }

    public function testRuleCanOnlyBeDisabledOnceAndRecordsActor(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $actorUserId = Uuid::uuid4()->toString();
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Аренда',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::OUTFLOW,
            CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build(),
            createdByUserId: $actorUserId,
        );

        self::assertSame(1, $rule->getRevision());
        self::assertSame($actorUserId, $rule->getCreatedByUserId());
        self::assertTrue($rule->disable($actorUserId));
        self::assertFalse($rule->isActive());
        self::assertSame(1, $rule->getRevision());
        self::assertNotNull($rule->getDisabledAt());
        self::assertSame($actorUserId, $rule->getDisabledByUserId());
        self::assertFalse($rule->disable($actorUserId));
        self::assertSame(1, $rule->getRevision());

        $this->expectException(\InvalidArgumentException::class);
        $rule->setIsActive(true);
    }

    /** @return list<string> */
    private function paths(ConstraintViolationListInterface $violations): array
    {
        return array_map(
            static fn (ConstraintViolationInterface $violation): string => $violation->getPropertyPath(),
            iterator_to_array($violations),
        );
    }
}
