<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash;

use App\Cash\Application\DTO\AutoRuleConditionInput;
use App\Cash\Application\DTO\AutoRuleInput;
use App\Cash\Application\DTO\CashflowCategoryInput;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Facade\CashFacade;
use App\Mcp\Application\Tool\CashCategoryUpsertTool;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Контракт CashFacade, на который опираются MCP-инструменты.
 */
final class CashFacadeMcpSurfaceTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111771';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-111111111772';

    public function testCreatesAndUpdatesCategory(): void
    {
        $this->createCompany(self::COMPANY_ID, 'mcp-facade-a@example.test');

        $rootId = $this->facade()->upsertCashflowCategory(self::COMPANY_ID, new CashflowCategoryInput(
            name: 'Операционные расходы',
            flowKind: CashflowFlowKind::OPERATING,
            sort: 10,
        ));

        $childId = $this->facade()->upsertCashflowCategory(self::COMPANY_ID, new CashflowCategoryInput(
            name: 'Подписки',
            parentId: $rootId,
        ));

        $renamedId = $this->facade()->upsertCashflowCategory(self::COMPANY_ID, new CashflowCategoryInput(
            id: $childId,
            name: 'Подписки и сервисы',
        ));

        self::assertSame($childId, $renamedId);

        $child = $this->em->getRepository(CashflowCategory::class)->find($childId);
        self::assertInstanceOf(CashflowCategory::class, $child);
        self::assertSame('Подписки и сервисы', $child->getName());
        self::assertSame($rootId, $child->getParent()?->getId());
        // flowKind наследуется от корня, даже если во входе его не было
        self::assertSame(CashflowFlowKind::OPERATING, $child->getFlowKind());

        $tree = $this->facade()->listCashflowCategories(self::COMPANY_ID);
        self::assertSame(['Операционные расходы', 'Подписки и сервисы'], array_column($tree, 'name'));
        self::assertSame([1, 2], array_column($tree, 'level'));
    }

    public function testCategoryOfAnotherCompanyIsNotReachableById(): void
    {
        $this->createCompany(self::COMPANY_ID, 'mcp-facade-b@example.test');
        $this->createCompany(self::OTHER_COMPANY_ID, 'mcp-facade-b-other@example.test');

        $foreignId = $this->facade()->upsertCashflowCategory(self::OTHER_COMPANY_ID, new CashflowCategoryInput(
            name: 'Чужая статья',
        ));

        self::assertSame([], $this->facade()->listCashflowCategories(self::COMPANY_ID));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('не найдена');

        $this->facade()->upsertCashflowCategory(self::COMPANY_ID, new CashflowCategoryInput(
            id: $foreignId,
            name: 'Захват чужой статьи',
        ));
    }

    public function testMcpCategoryToolDistinguishesOmittedParentFromExplicitNull(): void
    {
        $this->createCompany(self::COMPANY_ID, 'mcp-facade-parent@example.test');

        $rootId = $this->facade()->upsertCashflowCategory(self::COMPANY_ID, new CashflowCategoryInput(
            name: 'Инвестиционная деятельность',
            flowKind: CashflowFlowKind::INVESTING,
        ));
        $childId = $this->facade()->upsertCashflowCategory(self::COMPANY_ID, new CashflowCategoryInput(
            name: 'Оборудование',
            parentId: $rootId,
        ));

        $tool = self::getContainer()->get(CashCategoryUpsertTool::class);
        self::assertSame(['string', 'null'], $tool->inputSchema()['properties']['parentId']['type']);

        $tool->call(self::COMPANY_ID, ['id' => $childId, 'name' => 'Оборудование и мебель']);
        $this->em->clear();
        $renamed = $this->em->getRepository(CashflowCategory::class)->find($childId);
        self::assertInstanceOf(CashflowCategory::class, $renamed);
        self::assertSame($rootId, $renamed->getParent()?->getId());

        $tool->call(self::COMPANY_ID, ['id' => $childId, 'parentId' => null]);
        $this->em->clear();
        $detached = $this->em->getRepository(CashflowCategory::class)->find($childId);
        self::assertInstanceOf(CashflowCategory::class, $detached);
        self::assertNull($detached->getParent());
        self::assertSame(CashflowFlowKind::INVESTING, $detached->getFlowKind());
    }

    public function testMcpCategoryToolRejectsInvalidParentTypeInsteadOfDetaching(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UUID-строкой или null');

        self::getContainer()->get(CashCategoryUpsertTool::class)->call(
            self::COMPANY_ID,
            ['parentId' => 123],
        );
    }

    public function testCreatesAutoRuleAndReplacesConditions(): void
    {
        $this->createCompany(self::COMPANY_ID, 'mcp-facade-c@example.test');

        $categoryId = $this->facade()->upsertCashflowCategory(self::COMPANY_ID, new CashflowCategoryInput(
            name: 'Реклама',
        ));

        $ruleId = $this->facade()->upsertAutoRule(self::COMPANY_ID, new AutoRuleInput(
            name: 'Яндекс Директ',
            operationType: CashTransactionAutoRuleOperationType::OUTFLOW,
            cashflowCategoryId: $categoryId,
            conditions: [
                new AutoRuleConditionInput(
                    CashTransactionAutoRuleConditionField::DESCRIPTION,
                    CashTransactionAutoRuleConditionOperator::CONTAINS,
                    'Яндекс',
                ),
            ],
        ));

        $this->facade()->upsertAutoRule(self::COMPANY_ID, new AutoRuleInput(
            id: $ruleId,
            conditions: [
                new AutoRuleConditionInput(
                    CashTransactionAutoRuleConditionField::DESCRIPTION,
                    CashTransactionAutoRuleConditionOperator::CONTAINS,
                    'Директ',
                ),
                new AutoRuleConditionInput(
                    CashTransactionAutoRuleConditionField::AMOUNT,
                    CashTransactionAutoRuleConditionOperator::GREATER_THAN,
                    '1000.00',
                ),
            ],
        ));

        $this->em->clear();
        $rule = $this->em->getRepository(CashTransactionAutoRule::class)->find($ruleId);
        self::assertInstanceOf(CashTransactionAutoRule::class, $rule);
        self::assertCount(2, $rule->getConditions());
        self::assertSame('Яндекс Директ', $rule->getName());

        $listed = $this->facade()->listAutoRules(self::COMPANY_ID);
        self::assertCount(1, $listed);
        self::assertSame(['Директ', '1000.00'], array_column($listed[0]['conditions'], 'value'));
    }

    public function testAutoRuleWithoutConditionsIsRejected(): void
    {
        $this->createCompany(self::COMPANY_ID, 'mcp-facade-d@example.test');

        $categoryId = $this->facade()->upsertCashflowCategory(self::COMPANY_ID, new CashflowCategoryInput(
            name: 'Прочее',
        ));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('хотя бы одно условие');

        $this->facade()->upsertAutoRule(self::COMPANY_ID, new AutoRuleInput(
            name: 'Без условий',
            cashflowCategoryId: $categoryId,
        ));
    }

    public function testUnknownCompanyIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Компания');

        $this->facade()->listCashflowCategories('11111111-1111-1111-1111-111111111779');
    }

    private function createCompany(string $companyId, string $email): void
    {
        $owner = UserBuilder::aUser()
            ->withId(str_replace('1111-1111-1111-1111', '2222-2222-2222-2222', $companyId))
            ->withEmail($email)
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->withName('Company '.$companyId)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();
    }

    private function facade(): CashFacade
    {
        return self::getContainer()->get(CashFacade::class);
    }
}
