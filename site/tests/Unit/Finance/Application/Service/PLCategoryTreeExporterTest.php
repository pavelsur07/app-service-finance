<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Application\Service;

use App\Finance\Application\Service\PLCategoryTreeExporter;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLExpenseType;
use App\Finance\Enum\PLFlow;
use App\Finance\Enum\PLValueFormat;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use PHPUnit\Framework\TestCase;

final class PLCategoryTreeExporterTest extends TestCase
{
    /**
     * Файл обязан нести ВСЕ поля, которые импорт применяет к целевой категории
     * (ImportPLCategoryTreeAction::applyFields()). Молча потерянное поле = тихая
     * потеря настройки строки P&L при переносе между аккаунтами, которую видно
     * только в отчёте. Поэтому здесь сравнивается payload целиком, а каждое поле
     * имеет отличимое от дефолта значение.
     */
    public function testFilePayloadCarriesEveryImportableField(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->withName('Ромашка')->build();

        $root = PLCategoryBuilder::aPLCategory()->forCompany($company)
            ->withName('Выручка')->withCode('REVENUE')->withFlow(PLFlow::INCOME)
            ->withExpenseType(PLExpenseType::VARIABLE)->build();
        $root->setType(PLCategoryType::SUBTOTAL);
        $root->setFormat(PLValueFormat::PERCENT);
        $root->setWeightInParent('-0.5000');
        $root->setIsVisible(false);
        $root->setFormula('REVENUE - COGS');
        $root->setCalcOrder(7);
        $root->setSortOrder(30);

        $child = PLCategoryBuilder::aPLCategory()->forCompany($company)
            ->withName('Маркетплейсы')->withParent($root)->build();
        $child->setSortOrder(40);

        $payload = (new PLCategoryTreeExporter())->toFilePayload(
            (new PLCategoryTreeExporter())->fromEntities([$root, $child]),
            'Ромашка',
            new \DateTimeImmutable('2026-08-05T10:00:00+03:00'),
        );

        self::assertSame([
            'version' => 1,
            'exportedAt' => '2026-08-05T10:00:00+03:00',
            'company' => 'Ромашка',
            'categories' => [
                [
                    'name' => 'Выручка',
                    'code' => 'REVENUE',
                    'type' => 'SUBTOTAL',
                    'format' => 'PERCENT',
                    'flow' => 'INCOME',
                    'expenseType' => 'variable',
                    'weightInParent' => '-0.5000',
                    'isVisible' => false,
                    'formula' => 'REVENUE - COGS',
                    'calcOrder' => 7,
                    'sortOrder' => 30,
                    'children' => [
                        [
                            'name' => 'Маркетплейсы',
                            'code' => null,
                            'type' => 'LEAF_INPUT',
                            'format' => 'MONEY',
                            'flow' => 'EXPENSE',
                            'expenseType' => 'other',
                            'weightInParent' => '1.0000',
                            'isVisible' => true,
                            'formula' => null,
                            'calcOrder' => null,
                            'sortOrder' => 40,
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ], $payload);
    }

    public function testNodesKeepSourceOrderAndParentLinks(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();

        $root = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Расходы')->build();
        $child = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Реклама')->withParent($root)->build();
        $grandChild = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Ozon')->withParent($child)->build();

        $nodes = (new PLCategoryTreeExporter())->fromEntities([$root, $child, $grandChild]);

        self::assertCount(3, $nodes);
        self::assertNull($nodes[0]->parent);
        self::assertSame($nodes[0], $nodes[1]->parent);
        self::assertSame($nodes[1], $nodes[2]->parent);
        self::assertSame((string) $root->getId(), $nodes[0]->key);
    }

    public function testRejectsTreeWhereParentComesAfterChild(): void
    {
        // Матчинг импорта резолвит родителя по уже обойдённым узлам. Если
        // порядок нарушен, «тихо сделать узел корневым» означало бы переставить
        // категории в целевой компании — поэтому это ошибка, а не деградация.
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();

        $root = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Расходы')->build();
        $child = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Реклама')->withParent($root)->build();

        $this->expectException(\LogicException::class);

        (new PLCategoryTreeExporter())->fromEntities([$child, $root]);
    }

    public function testChildrenAreNestedUnderTheirOwnParentOnly(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();

        $first = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Первый корень')->build();
        $firstChild = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Потомок первого')->withParent($first)->build();
        $second = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Второй корень')->build();

        $exporter = new PLCategoryTreeExporter();
        $payload = $exporter->toFilePayload(
            $exporter->fromEntities([$first, $firstChild, $second]),
            'Ромашка',
            new \DateTimeImmutable('2026-08-05T10:00:00+03:00'),
        );

        self::assertCount(2, $payload['categories']);
        self::assertSame('Первый корень', $payload['categories'][0]['name']);
        self::assertCount(1, $payload['categories'][0]['children']);
        self::assertSame('Второй корень', $payload['categories'][1]['name']);
        self::assertSame([], $payload['categories'][1]['children']);
    }

    public function testExportsEmptyTree(): void
    {
        $payload = (new PLCategoryTreeExporter())->toFilePayload([], 'Ромашка', new \DateTimeImmutable('2026-08-05T10:00:00+03:00'));

        self::assertSame([], $payload['categories']);
    }
}
