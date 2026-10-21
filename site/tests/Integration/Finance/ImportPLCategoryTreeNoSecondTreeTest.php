<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Enum\BusinessProfile;
use App\Finance\Application\Action\ImportPLCategoryTreeAction;
use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Application\DTO\ImportPLCategoryTreeResult;
use App\Finance\Application\DTO\ImportPLCategoryTreeRow;
use App\Finance\Application\DTO\ImportPLCategoryTreeSimilarRow;
use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLExpenseType;
use App\Finance\Enum\PLFlow;
use App\Finance\Enum\PLValueFormat;
use App\Finance\Exception\PLTreeWouldDuplicateException;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Импорт ОПиУ не строит второе дерево рядом с деревом компании и не дублирует статьи с похожими
 * названиями (dry-run на проде 21.10: шаблон поставил бы 72 статьи рядом со своими у пяти компаний).
 */
final class ImportPLCategoryTreeNoSecondTreeTest extends IntegrationTestCase
{
    #[DataProvider('modes')]
    public function testTreeWithoutTemplateCodesIsRefusedAndNothingWritten(bool $dryRun): void
    {
        $company = $this->company();
        $this->ownTree($company);

        try {
            $this->applyTemplate($company, $dryRun);
            self::fail('Шаблон не должен вставать вторым деревом');
        } catch (PLTreeWouldDuplicateException $e) {
            self::assertStringContainsString('Шаблон опознал 0 из 3 корневых статей', $e->getMessage());
            self::assertStringContainsString('Сопоставьте', $e->getMessage());
        }

        self::assertSame(5, $this->categoryCount($company));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'предпросмотр' => [true];
        yield 'применение' => [false];
    }

    public function testTemplateTreeWithMissingRootsIsCompleted(): void
    {
        $company = $this->templateCompany();
        $this->deleteCodes($company, ['DRR', 'CM4', 'CM4K']);
        $this->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Шоурум')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build());

        $result = $this->applyTemplate($company, false);

        self::assertSame(['CM4', 'CM4K', 'DRR'], $this->codes($result->created));
        self::assertSame([19, 18], [$result->companyRoots, $result->recognizedRoots]);
        self::assertSame(73, $this->categoryCount($company));
    }

    public function testSimilarNamedCompanyLineIsNotDuplicatedAndIsReported(): void
    {
        $company = $this->templateCompany();
        $this->deleteCodes($company, ['BELOW_EBITDA_PROFIT_TAX', 'BELOW_EBITDA_DEPRECIATION']);
        $below = $this->byCode($company, 'BELOW_EBITDA_TOTAL');
        $own = PLCategoryBuilder::aPLCategory()->forCompany($company)->withParent($below)->withName('Налог на прибыль / УСН')->withCode(null)->build();
        $this->persist($own);

        $result = $this->applyTemplate($company, false);

        self::assertSame(['BELOW_EBITDA_DEPRECIATION'], $this->codes($result->created));
        self::assertEquals([new ImportPLCategoryTreeSimilarRow('Налог на прибыль', 'BELOW_EBITDA_PROFIT_TAX', 'Расходы ниже EBITDA / Налог на прибыль', (string) $own->getId(), 'Налог на прибыль / УСН')], $result->similar);
        self::assertNull($this->byCode($company, 'BELOW_EBITDA_PROFIT_TAX', required: false));
    }

    public function testSimilarGroupIsSkippedWithItsNewChildren(): void
    {
        $company = $this->templateCompany();
        $this->deleteCodes($company, ['BELOW_EBITDA_DEPRECIATION', 'BELOW_EBITDA_VAT', 'BELOW_EBITDA_USN', 'BELOW_EBITDA_PROFIT_TAX', 'BELOW_EBITDA_INTEREST', 'BELOW_EBITDA_TOTAL']);
        $this->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Расходы ниже EBITDA')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build());

        $result = $this->applyTemplate($company, false);

        self::assertSame([], $result->created);
        self::assertSame(['BELOW_EBITDA_TOTAL'], array_map(static fn (ImportPLCategoryTreeSimilarRow $r): ?string => $r->code, $result->similar));
        self::assertSame(['BELOW_EBITDA_DEPRECIATION', 'BELOW_EBITDA_INTEREST', 'BELOW_EBITDA_PROFIT_TAX', 'BELOW_EBITDA_USN', 'BELOW_EBITDA_VAT'], $this->codes($result->skippedWithSimilar), 'пропущенные потомки видны в отчёте');
        self::assertSame(67, $this->categoryCount($company));
    }

    public function testOneCompanyLineSuppressesAtMostOneTemplateLine(): void
    {
        $company = $this->templateCompany();
        // «Сервисы и подписки» есть в шаблоне дважды (административные и «(коммерческие)»).
        $this->deleteCodes($company, ['OVERHEAD_ADMIN_SUBS', 'OVERHEAD_SALES_SUBS']);
        $admin = $this->byCode($company, 'OVERHEAD_ADMIN_TOTAL');
        $this->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withParent($admin)->withName('Сервисы и подписки')->withCode(null)->build());

        $result = $this->applyTemplate($company, false);

        self::assertCount(1, $result->similar);
        self::assertCount(1, $result->created);
    }

    /**
     * Точное совпадение в приоритете: «Аренда / склад» шаблона не забирает статью компании
     * «Аренда», точно совпадающую со следующей статьёй шаблона.
     */
    public function testExactMatchWinsOverSeparatorMatch(): void
    {
        $company = $this->company();
        $root = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Расходы')->withCode('ROOT')->withType(PLCategoryType::SUBTOTAL)->build();
        $this->persist($root, PLCategoryBuilder::aPLCategory()->forCompany($company)->withParent($root)->withName('Аренда')->withCode(null)->build());
        $node = static fn (string $key, ?PLCategoryTreeNode $parent, string $name, string $code, PLCategoryType $type, int $sort): PLCategoryTreeNode => new PLCategoryTreeNode($key, $parent, $name, $code, $type, PLValueFormat::MONEY, PLFlow::EXPENSE, PLExpenseType::OTHER, '1.0000', true, null, null, $sort);
        $sourceRoot = $node('r', null, 'Расходы', 'ROOT', PLCategoryType::SUBTOTAL, 10);
        $source = [$sourceRoot, $node('x', $sourceRoot, 'Аренда / склад', 'X', PLCategoryType::LEAF_INPUT, 10), $node('y', $sourceRoot, 'Аренда', 'Y', PLCategoryType::LEAF_INPUT, 20)];

        $result = (self::getContainer()->get(ImportPLCategoryTreeAction::class))(new ImportPLCategoryTreeCommand($source, (string) $company->getId(), true, onlyCreate: true));

        self::assertSame(['X'], $this->codes($result->created));
        self::assertSame(['Y'], array_map(static fn (ImportPLCategoryTreeSimilarRow $r): ?string => $r->code, $result->similar));
    }

    /**
     * Регрессия (ревью): корень компании, сопоставленный со вложенной статьёй шаблона, остаётся
     * корнем — корень шаблона над ним встал бы рядом, поэтому опознанным он не считается.
     */
    public function testRootMatchedByNestedTemplateLineIsNotRecognized(): void
    {
        $company = $this->company();
        $this->persist(
            PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Продажи')->withCode('REV_NOT_SPP_TOTAL')->withType(PLCategoryType::SUBTOTAL)->build(),
            PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Затраты')->withCode('COGS_TOTAL')->withType(PLCategoryType::SUBTOTAL)->build(),
        );

        // Считая «Продажи» опознанной, вышло бы 2 из 3 — шаблон встал бы рядом с ними своей «Выручкой».
        $this->expectException(PLTreeWouldDuplicateException::class);
        $this->expectExceptionMessage('Шаблон опознал 1 из 3 корневых статей');

        $this->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Шоурум')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build());
        $this->applyTemplate($company, true);
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function boundaries(): iterable
    {
        yield 'ровно половина — можно' => [2, 2, true];
        yield 'меньше половины — нельзя' => [1, 2, false];
    }

    #[DataProvider('boundaries')]
    public function testHalfOfRootsIsTheBoundary(int $templateRoots, int $ownRoots, bool $allowed): void
    {
        $company = $this->company();
        foreach (array_slice(['COGS_TOTAL', 'NET_PROFIT'], 0, $templateRoots) as $code) {
            $type = 'NET_PROFIT' === $code ? PLCategoryType::KPI : PLCategoryType::SUBTOTAL;
            $this->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Своя '.$code)->withCode($code)->withType($type)->build());
        }
        for ($i = 1; $i <= $ownRoots; ++$i) {
            $this->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Своя группа '.$i)->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build());
        }

        if (!$allowed) {
            $this->expectException(PLTreeWouldDuplicateException::class);
        }
        $result = $this->applyTemplate($company, true);

        self::assertSame([$templateRoots + $ownRoots, $templateRoots], [$result->companyRoots, $result->recognizedRoots]);
    }

    public function testCompanyLineRecognizedByCodeIsNotACandidate(): void
    {
        $company = $this->templateCompany();
        $this->deleteCodes($company, ['BELOW_EBITDA_PROFIT_TAX']);
        // Своё название у статьи с кодом шаблона: она уже «УСН», а не похожий дубль налога на прибыль.
        $usn = $this->byCode($company, 'BELOW_EBITDA_USN');
        self::assertInstanceOf(PLCategory::class, $usn);
        $usn->setName('Налог на прибыль');
        $this->em->flush();

        $result = $this->applyTemplate($company, false);

        self::assertSame(['BELOW_EBITDA_PROFIT_TAX'], $this->codes($result->created));
        self::assertSame([], $result->similar);
    }

    public function testImportFromAnotherSourceIntoUnrelatedTreeIsRefused(): void
    {
        $company = $this->company();
        $this->ownTree($company);
        $source = [new PLCategoryTreeNode('n1', null, 'Расходы', 'EXP', PLCategoryType::SUBTOTAL, PLValueFormat::MONEY, PLFlow::EXPENSE, PLExpenseType::OTHER, '1.0000', true, null, null, 10)];

        $this->expectException(PLTreeWouldDuplicateException::class);
        $this->expectExceptionMessage('Источник опознал 0 из 3 корневых статей');

        (self::getContainer()->get(ImportPLCategoryTreeAction::class))(new ImportPLCategoryTreeCommand($source, (string) $company->getId(), true));
    }

    private function company(): Company
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $this->persist($owner, $company);

        return $company;
    }

    private function templateCompany(): Company
    {
        $company = $this->company();
        self::assertCount(72, $this->applyTemplate($company, false)->created);
        $this->em->clear();
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }

    /**
     * Своё дерево без кодов: 3 корня и 2 вложенные статьи.
     */
    private function ownTree(Company $company): void
    {
        $revenue = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Выручка')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build();
        $this->persist(
            $revenue,
            PLCategoryBuilder::aPLCategory()->forCompany($company)->withParent($revenue)->withName('Продажи WB')->withCode(null)->build(),
            PLCategoryBuilder::aPLCategory()->forCompany($company)->withParent($revenue)->withName('Продажи Ozon')->withCode(null)->build(),
            PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Переменные расходы')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build(),
            PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Чистая прибыль')->withCode(null)->withType(PLCategoryType::KPI)->build(),
        );
    }

    private function applyTemplate(Company $company, bool $dryRun): ImportPLCategoryTreeResult
    {
        return (self::getContainer()->get(ImportPLCategoryTreeAction::class))(new ImportPLCategoryTreeCommand(
            self::getContainer()->get(DefaultPLCategoryTreeProvider::class)->nodes(BusinessProfile::MARKETPLACE),
            (string) $company->getId(),
            $dryRun,
            onlyCreate: true,
        ));
    }

    /**
     * @param list<string> $codes
     */
    private function deleteCodes(Company $company, array $codes): void
    {
        foreach ($codes as $code) {
            self::assertSame(1, $this->connection->delete('pl_categories', ['company_id' => (string) $company->getId(), 'code' => $code]));
        }
    }

    private function byCode(Company $company, string $code, bool $required = true): ?PLCategory
    {
        $category = $this->em->getRepository(PLCategory::class)->findOneBy(['company' => $company->getId(), 'code' => $code]);
        if ($required) {
            self::assertInstanceOf(PLCategory::class, $category);
        }

        return $category instanceof PLCategory ? $category : null;
    }

    private function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    private function categoryCount(Company $company): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pl_categories WHERE company_id = ?', [(string) $company->getId()]);
    }

    /**
     * @param array<ImportPLCategoryTreeRow> $rows
     *
     * @return list<string>
     */
    private function codes(array $rows): array
    {
        $codes = array_map(static fn (ImportPLCategoryTreeRow $r): string => (string) $r->code, $rows);
        sort($codes);

        return $codes;
    }
}
