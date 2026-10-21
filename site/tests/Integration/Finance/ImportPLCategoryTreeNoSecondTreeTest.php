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
        self::assertSame(67, $this->categoryCount($company));
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
