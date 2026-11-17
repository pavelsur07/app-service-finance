<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application\Service\AutoRule;

use App\Cash\Application\Service\AutoRule\CashAutoRuleMatcher;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CashAutoRuleMatcherTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, bool}> */
    public static function paymentPurposes(): iterable
    {
        yield 'substring' => ['CONTAINS', 'ПО ДОГОВОРУ АРЕНДЫ', true];
        yield 'excluded' => ['NOT_CONTAINS', 'Обеспечительный платеж', false];
        yield 'not excluded' => ['NOT_CONTAINS', 'по договору аренды', true];
        yield 'exact folded' => ['EQUAL', 'ОБЕСПЕЧИТЕЛЬНЫЙ ПЛАТЕЖ', true];
        yield 'not exact' => ['EQUAL', 'Это обеспечительный платёж', false];
        foreach (['CONTAINS', 'NOT_CONTAINS', 'EQUAL'] as $operator) {
            yield 'empty '.$operator => [$operator, null, false];
            yield 'blank '.$operator => [$operator, '   ', false];
        }
    }

    #[DataProvider('paymentPurposes')]
    public function testPaymentPurpose(string $operator, ?string $purpose, bool $expected): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $rule = new CashTransactionAutoRule(Uuid::uuid7()->toString(), $company, 'Аренда', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY);
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::from($operator),
            value: 'CONTAINS' === $operator ? 'договору' : 'обеспечительный платёж',
        ));
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $transaction->setDescription($purpose);

        self::assertSame($expected, (new CashAutoRuleMatcher())->matches($rule, $transaction));
    }

    public function testSnapshotOrderDoesNotChangeWinnersOrFieldConflicts(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();
        $first = new CashTransactionAutoRule('11111111-1111-4111-8111-111111111111', $company, 'First', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY, $category);
        $second = new CashTransactionAutoRule('22222222-2222-4222-8222-222222222222', $company, 'Second', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY, $category);
        $first->setProjectDirection(new ProjectDirection(Uuid::uuid4()->toString(), $company, 'First project'));
        $second->setProjectDirection(new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Second project'));
        $matcher = new CashAutoRuleMatcher();

        $forward = $matcher->match($transaction, [$first, $second]);
        $reverse = $matcher->match($transaction, [$second, $first]);

        self::assertEquals($forward, $reverse);
        self::assertSame($first, $forward->winners['cashflowCategory']);
        self::assertArrayNotHasKey('projectDirection', $forward->winners);
        self::assertSame([$first, $second], $forward->conflicts['projectDirection']);
        self::assertSame([$first, $second], $forward->conflictingRules);
        self::assertNull($transaction->getCashflowCategory());
        self::assertNull($transaction->getProjectDirection());
    }

    public function testSnapshotRejectsForeignRuleAndSkipsDeletedTransaction(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $foreignCompany = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $rule = new CashTransactionAutoRule(Uuid::uuid4()->toString(), $foreignCompany, 'Foreign', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY);
        $matcher = new CashAutoRuleMatcher();
        self::assertFalse($matcher->matches($rule, $transaction));
        self::assertSame([], $matcher->match($transaction, [$rule])->matchingRules);
        $rule = new CashTransactionAutoRule(Uuid::uuid4()->toString(), $company, 'Local', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY);
        $transaction->markDeleted(null);
        self::assertSame([], $matcher->match($transaction, [$rule])->matchingRules);
    }
}
