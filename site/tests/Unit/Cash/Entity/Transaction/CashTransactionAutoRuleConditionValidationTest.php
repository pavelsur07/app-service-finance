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
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('validExactSignalProvider')]
    public function testAcceptsNewExactSignal(
        CashTransactionAutoRuleConditionField $field,
        string $value,
    ): void {
        $condition = new CashTransactionAutoRuleCondition(
            field: $field,
            operator: CashTransactionAutoRuleConditionOperator::EQUAL,
            value: $value,
        );

        self::assertCount(0, $this->validator->validate($condition));
    }

    /**
     * @return iterable<string, array{CashTransactionAutoRuleConditionField, string}>
     */
    public static function validExactSignalProvider(): iterable
    {
        yield 'currency' => [CashTransactionAutoRuleConditionField::CURRENCY, 'RUB'];
        yield 'import source' => [CashTransactionAutoRuleConditionField::IMPORT_SOURCE, 'telegram'];
        yield 'missing import source' => [
            CashTransactionAutoRuleConditionField::IMPORT_SOURCE,
            CashTransactionAutoRuleCondition::MISSING_IMPORT_SOURCE_VALUE,
        ];
        yield 'transfer' => [CashTransactionAutoRuleConditionField::IS_TRANSFER, 'false'];
        yield 'document type' => [CashTransactionAutoRuleConditionField::DOCUMENT_TYPE, 'Платёжное поручение'];
    }

    #[DataProvider('invalidExactSignalProvider')]
    public function testRejectsInvalidNewExactSignal(
        CashTransactionAutoRuleConditionField $field,
        CashTransactionAutoRuleConditionOperator $operator,
        string $value,
        string $expectedPath,
    ): void {
        $condition = new CashTransactionAutoRuleCondition(
            field: $field,
            operator: $operator,
            value: $value,
        );

        self::assertContains($expectedPath, $this->paths($this->validator->validate($condition)));
    }

    /**
     * @return iterable<string, array{CashTransactionAutoRuleConditionField, CashTransactionAutoRuleConditionOperator, string, string}>
     */
    public static function invalidExactSignalProvider(): iterable
    {
        yield 'lowercase currency' => [
            CashTransactionAutoRuleConditionField::CURRENCY,
            CashTransactionAutoRuleConditionOperator::EQUAL,
            'rub',
            'value',
        ];
        yield 'source with spaces' => [
            CashTransactionAutoRuleConditionField::IMPORT_SOURCE,
            CashTransactionAutoRuleConditionOperator::EQUAL,
            'bank source',
            'value',
        ];
        yield 'non-canonical boolean' => [
            CashTransactionAutoRuleConditionField::IS_TRANSFER,
            CashTransactionAutoRuleConditionOperator::EQUAL,
            '1',
            'value',
        ];
        yield 'long document type' => [
            CashTransactionAutoRuleConditionField::DOCUMENT_TYPE,
            CashTransactionAutoRuleConditionOperator::EQUAL,
            str_repeat('a', 65),
            'value',
        ];
        yield 'unsupported operator' => [
            CashTransactionAutoRuleConditionField::DOCUMENT_TYPE,
            CashTransactionAutoRuleConditionOperator::CONTAINS,
            'invoice',
            'operator',
        ];
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

    public function testConditionRequiresMoneyAccount(): void
    {
        $condition = new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::MONEY_ACCOUNT,
            operator: CashTransactionAutoRuleConditionOperator::EQUAL,
        );

        self::assertContains('moneyAccount', $this->paths($this->validator->validate($condition)));
    }

    public function testConditionRejectsMoneyAccountFromAnotherCompany(): void
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
            field: CashTransactionAutoRuleConditionField::MONEY_ACCOUNT,
            operator: CashTransactionAutoRuleConditionOperator::EQUAL,
            moneyAccount: MoneyAccountBuilder::aMoneyAccount()->forCompany($otherCompany)->build(),
        );

        self::assertContains('moneyAccount', $this->paths($this->validator->validate($condition)));
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

    public function testRuleTogglesActivityOnceAtATimeAndRecordsActor(): void
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

        // Отключение обратимо: правило можно включить и следы отключения снимаются.
        self::assertTrue($rule->enable($actorUserId));
        self::assertTrue($rule->isActive());
        self::assertNull($rule->getDisabledAt());
        self::assertNull($rule->getDisabledByUserId());
        self::assertFalse($rule->enable($actorUserId));

        self::assertFalse($rule->setIsActive(false)->isActive());
        self::assertTrue($rule->setIsActive(true)->isActive());
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
