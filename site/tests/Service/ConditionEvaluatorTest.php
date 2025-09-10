<?php

namespace App\Tests\Service;

use App\Entity\AutoCategoryCondition;
use App\Entity\AutoCategoryTemplate;
use App\Entity\Company;
use App\Entity\User;
use App\Enum\AutoTemplateDirection;
use App\Enum\AutoTemplateScope;
use App\Enum\ConditionField;
use App\Enum\ConditionOperator;
use App\Enum\MatchLogic;
use App\Service\AutoCategory\ConditionEvaluator;
use Ramsey\Uuid\Uuid;
use PHPUnit\Framework\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    private AutoCategoryTemplate $template;
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $this->template = new AutoCategoryTemplate(Uuid::uuid4()->toString(), $company);
        $this->template->setScope(AutoTemplateScope::CASHFLOW);
        $this->template->setDirection(AutoTemplateDirection::ANY);
        $this->template->setMatchLogic(MatchLogic::ALL);
        $this->evaluator = new ConditionEvaluator();
    }

    private function createCondition(ConditionField $field, ConditionOperator $operator, string $value, bool $caseSensitive = false, bool $negate = false): AutoCategoryCondition
    {
        $cond = new AutoCategoryCondition(Uuid::uuid4()->toString(), $this->template);
        $cond->setField($field);
        $cond->setOperator($operator);
        $cond->setValue($value);
        $cond->setCaseSensitive($caseSensitive);
        $cond->setNegate($negate);
        return $cond;
    }

    public function testContainsCaseSensitive(): void
    {
        $cond = $this->createCondition(ConditionField::DESCRIPTION, ConditionOperator::CONTAINS, 'pay', false);
        $op = ['description' => 'Payment'];
        $this->assertTrue($this->evaluator->isConditionMatched($op, $cond));

        $condCs = $this->createCondition(ConditionField::DESCRIPTION, ConditionOperator::CONTAINS, 'pay', true);
        $this->assertFalse($this->evaluator->isConditionMatched($op, $condCs));
    }

    public function testRegex(): void
    {
        $cond = $this->createCondition(ConditionField::DESCRIPTION, ConditionOperator::REGEX, '^Pay', false);
        $op = ['description' => 'Payment'];
        $this->assertTrue($this->evaluator->isConditionMatched($op, $cond));

        $condBad = $this->createCondition(ConditionField::DESCRIPTION, ConditionOperator::REGEX, '[', false);
        $this->assertFalse($this->evaluator->isConditionMatched($op, $condBad));
    }

    public function testBetweenNumbersAndDates(): void
    {
        $condNum = $this->createCondition(ConditionField::AMOUNT, ConditionOperator::BETWEEN, '100..200');
        $op = ['amount' => 150];
        $this->assertTrue($this->evaluator->isConditionMatched($op, $condNum));
        $op2 = ['amount' => 50];
        $this->assertFalse($this->evaluator->isConditionMatched($op2, $condNum));

        $condDate = $this->createCondition(ConditionField::DATE, ConditionOperator::BETWEEN, '2025-09-01..2025-09-10');
        $opDate = ['date' => new \DateTimeImmutable('2025-09-05')];
        $this->assertTrue($this->evaluator->isConditionMatched($opDate, $condDate));
        $opDate2 = ['date' => new \DateTimeImmutable('2025-09-20')];
        $this->assertFalse($this->evaluator->isConditionMatched($opDate2, $condDate));
    }

    public function testInNotIn(): void
    {
        $condIn = $this->createCondition(ConditionField::PLAT_INN, ConditionOperator::IN, '["123","456"]');
        $op = ['plat_inn' => '123'];
        $this->assertTrue($this->evaluator->isConditionMatched($op, $condIn));
        $condNotIn = $this->createCondition(ConditionField::PLAT_INN, ConditionOperator::NOT_IN, '["123","456"]');
        $this->assertFalse($this->evaluator->isConditionMatched($op, $condNotIn));
    }

    public function testNegate(): void
    {
        $cond = $this->createCondition(ConditionField::DESCRIPTION, ConditionOperator::CONTAINS, 'pay', false, true);
        $op = ['description' => 'Payment'];
        $this->assertFalse($this->evaluator->isConditionMatched($op, $cond));
    }
}
