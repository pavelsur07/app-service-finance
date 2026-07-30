<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application\Service;

use App\Cash\Application\Service\CashTransactionAutoRulePrefiller;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CashTransactionAutoRulePrefillerTest extends TestCase
{
    public function testCounterpartyBecomesTheConditionAndDirectionIsFixed(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();
        $category = $this->category($company, 'Аренда');
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withCompany($company)
            ->withName('ООО Ромашка')
            ->build();

        $transaction = $this->transaction($company, CashDirection::INFLOW, $category);
        $transaction->setCounterparty($counterparty);

        $rule = $this->rule($company);
        (new CashTransactionAutoRulePrefiller())->prefill($rule, $transaction, [$category]);

        self::assertSame(CashTransactionAutoRuleOperationType::INFLOW, $rule->getOperationType());
        self::assertSame($category, $rule->getCashflowCategory());
        self::assertSame('ООО Ромашка → Аренда', $rule->getName());

        self::assertCount(1, $rule->getConditions());
        $condition = $rule->getConditions()->first();
        self::assertSame(CashTransactionAutoRuleConditionField::COUNTERPARTY, $condition->getField());
        self::assertSame(CashTransactionAutoRuleConditionOperator::EQUAL, $condition->getOperator());
        self::assertSame($counterparty, $condition->getCounterparty());
    }

    public function testWithoutCounterpartyConditionIsEmptyDescriptionContains(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();
        $category = $this->category($company, 'Комиссия банка');

        $transaction = $this->transaction($company, CashDirection::OUTFLOW, $category);
        $transaction->setDescription('Комиссия за перевод по счету №77 от 03.04.2026');

        $rule = $this->rule($company);
        (new CashTransactionAutoRulePrefiller())->prefill($rule, $transaction, [$category]);

        self::assertSame(CashTransactionAutoRuleOperationType::OUTFLOW, $rule->getOperationType());
        self::assertSame('Комиссия банка', $rule->getName());

        $condition = $rule->getConditions()->first();
        self::assertSame(CashTransactionAutoRuleConditionField::DESCRIPTION, $condition->getField());
        self::assertSame(CashTransactionAutoRuleConditionOperator::CONTAINS, $condition->getOperator());
        // Назначение платежа целиком в условие не попадает: такое правило совпало бы
        // ровно с одной операцией.
        self::assertNull($condition->getValue());
    }

    public function testUnallocatedPlaceholderIsNotUsedAsRuleTarget(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();
        $unallocated = $this->category($company, 'Не распределено');
        $unallocated->markAsSystem(CashflowCategory::CODE_UNALLOCATED);

        $rule = $this->rule($company);
        (new CashTransactionAutoRulePrefiller())->prefill(
            $rule,
            $this->transaction($company, CashDirection::OUTFLOW, $unallocated),
            [$unallocated],
        );

        self::assertNull($rule->getCashflowCategory());
        self::assertSame('', $rule->getName());
    }

    public function testCategoryMissingFromFormChoicesIsSkipped(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();
        $category = $this->category($company, 'Архивная статья');

        $rule = $this->rule($company);
        (new CashTransactionAutoRulePrefiller())->prefill(
            $rule,
            $this->transaction($company, CashDirection::OUTFLOW, $category),
            [],
        );

        self::assertNull($rule->getCashflowCategory());
    }

    private function rule(Company $company): CashTransactionAutoRule
    {
        return new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            '',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
        );
    }

    private function category(Company $company, string $name): CashflowCategory
    {
        return CashflowCategoryBuilder::aCashflowCategory()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->withName($name)
            ->build();
    }

    private function transaction(
        Company $company,
        CashDirection $direction,
        ?CashflowCategory $category,
    ): CashTransaction {
        return CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withMoneyAccount(
                MoneyAccountBuilder::aMoneyAccount()
                    ->withId(Uuid::uuid4()->toString())
                    ->forCompany($company)
                    ->build(),
            )
            ->withDirection($direction)
            ->withCashflowCategory($category)
            ->build();
    }
}
