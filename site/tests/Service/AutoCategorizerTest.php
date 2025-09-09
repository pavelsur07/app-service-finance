<?php

namespace App\Tests\Service;

use App\Entity\AutoCategoryCondition;
use App\Entity\AutoCategoryTemplate;
use App\Entity\CashflowCategory;
use App\Entity\Company;
use App\Entity\User;
use App\Enum\AutoTemplateDirection;
use App\Enum\AutoTemplateScope;
use App\Enum\ConditionField;
use App\Enum\ConditionOperator;
use App\Enum\MatchLogic;
use App\Repository\AutoCategoryTemplateRepository;
use App\Service\AutoCategory\AutoCategorizer;
use App\Service\AutoCategory\ConditionEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

class AutoCategorizerTest extends TestCase
{
    private Company $company;
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $this->company = new Company(Uuid::uuid4()->toString(), $user);
        $this->evaluator = new ConditionEvaluator();
    }

    private function createTemplate(int $priority, CashflowCategory $category, AutoTemplateDirection $direction = AutoTemplateDirection::ANY, bool $stopOnMatch = true, MatchLogic $logic = MatchLogic::ALL): AutoCategoryTemplate
    {
        $template = new AutoCategoryTemplate(Uuid::uuid4()->toString(), $this->company);
        $template->setScope(AutoTemplateScope::CASHFLOW);
        $template->setPriority($priority);
        $template->setTargetCategory($category);
        $template->setDirection($direction);
        $template->setStopOnMatch($stopOnMatch);
        $template->setMatchLogic($logic);
        return $template;
    }

    private function addCondition(AutoCategoryTemplate $template, ConditionField $field, ConditionOperator $op, string $value): void
    {
        $cond = new AutoCategoryCondition(Uuid::uuid4()->toString(), $template);
        $cond->setField($field);
        $cond->setOperator($op);
        $cond->setValue($value);
        $template->addCondition($cond);
    }

    public function testPriority(): void
    {
        $catA = new CashflowCategory(Uuid::uuid4()->toString(), $this->company); $catA->setName('A');
        $catB = new CashflowCategory(Uuid::uuid4()->toString(), $this->company); $catB->setName('B');
        $t1 = $this->createTemplate(1, $catA, stopOnMatch: true);
        $t2 = $this->createTemplate(2, $catB, stopOnMatch: true);
        $this->addCondition($t1, ConditionField::DESCRIPTION, ConditionOperator::CONTAINS, 'foo');
        $this->addCondition($t2, ConditionField::DESCRIPTION, ConditionOperator::CONTAINS, 'foo');
        $repo = $this->createMock(AutoCategoryTemplateRepository::class);
        $repo->method('findActiveForCashflowByDirection')->willReturn([$t1, $t2]);
        $categorizer = new AutoCategorizer($repo, $this->evaluator, new NullLogger());
        $op = ['description' => 'foo'];
        $res = $categorizer->resolveCashflowCategory($this->company, $op, AutoTemplateDirection::INFLOW);
        $this->assertSame($catA, $res);
    }

    public function testDirectionFiltering(): void
    {
        $cat = new CashflowCategory(Uuid::uuid4()->toString(), $this->company); $cat->setName('A');
        $tIn = $this->createTemplate(1, $cat, AutoTemplateDirection::INFLOW);
        $this->addCondition($tIn, ConditionField::DESCRIPTION, ConditionOperator::CONTAINS, 'foo');
        $repo = $this->createMock(AutoCategoryTemplateRepository::class);
        $repo->expects($this->once())->method('findActiveForCashflowByDirection')->with($this->company, AutoTemplateDirection::INFLOW)->willReturn([$tIn]);
        $categorizer = new AutoCategorizer($repo, $this->evaluator, new NullLogger());
        $res = $categorizer->resolveCashflowCategory($this->company, ['description' => 'foo'], AutoTemplateDirection::INFLOW);
        $this->assertSame($cat, $res);
    }

    public function testMatchLogic(): void
    {
        $cat = new CashflowCategory(Uuid::uuid4()->toString(), $this->company); $cat->setName('A');
        $tAny = $this->createTemplate(1, $cat, matchLogic: MatchLogic::ANY);
        $this->addCondition($tAny, ConditionField::DESCRIPTION, ConditionOperator::CONTAINS, 'foo');
        $this->addCondition($tAny, ConditionField::PLAT_INN, ConditionOperator::EQUALS, '123');
        $repo = $this->createMock(AutoCategoryTemplateRepository::class);
        $repo->method('findActiveForCashflowByDirection')->willReturn([$tAny]);
        $categorizer = new AutoCategorizer($repo, $this->evaluator, new NullLogger());
        $res = $categorizer->resolveCashflowCategory($this->company, ['description' => 'bar', 'plat_inn' => '123'], AutoTemplateDirection::ANY);
        $this->assertSame($cat, $res);
    }

    public function testStopOnMatch(): void
    {
        $catA = new CashflowCategory(Uuid::uuid4()->toString(), $this->company); $catA->setName('A');
        $catB = new CashflowCategory(Uuid::uuid4()->toString(), $this->company); $catB->setName('B');
        $t1 = $this->createTemplate(1, $catA, stopOnMatch: false);
        $t2 = $this->createTemplate(2, $catB, stopOnMatch: true);
        $this->addCondition($t1, ConditionField::DESCRIPTION, ConditionOperator::CONTAINS, 'foo');
        $this->addCondition($t2, ConditionField::DESCRIPTION, ConditionOperator::CONTAINS, 'foo');
        $repo = $this->createMock(AutoCategoryTemplateRepository::class);
        $repo->method('findActiveForCashflowByDirection')->willReturn([$t1, $t2]);
        $categorizer = new AutoCategorizer($repo, $this->evaluator, new NullLogger());
        $res = $categorizer->resolveCashflowCategory($this->company, ['description' => 'foo'], AutoTemplateDirection::ANY);
        $this->assertSame($catB, $res);
    }

    public function testNoMatchReturnsNull(): void
    {
        $cat = new CashflowCategory(Uuid::uuid4()->toString(), $this->company); $cat->setName('A');
        $t = $this->createTemplate(1, $cat);
        $this->addCondition($t, ConditionField::DESCRIPTION, ConditionOperator::CONTAINS, 'foo');
        $repo = $this->createMock(AutoCategoryTemplateRepository::class);
        $repo->method('findActiveForCashflowByDirection')->willReturn([$t]);
        $categorizer = new AutoCategorizer($repo, $this->evaluator, new NullLogger());
        $res = $categorizer->resolveCashflowCategory($this->company, ['description' => 'bar'], AutoTemplateDirection::ANY);
        $this->assertNull($res);
    }
}
