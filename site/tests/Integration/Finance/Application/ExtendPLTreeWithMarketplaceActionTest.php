<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Application;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Enum\BusinessProfile;
use App\Finance\Application\Action\ApplyDefaultPLTemplateAction;
use App\Finance\Application\Action\EnsureDefaultPLTreeAction;
use App\Finance\Application\Action\ExtendPLTreeWithMarketplaceAction;
use App\Finance\Application\DTO\ExtendPLTreeResult;
use App\Finance\Application\DTO\PLFormulaChange;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLTemplateApplicationSource;
use App\Finance\Exception\PLTreeExtensionException;
use App\Finance\Facade\FinanceFacade;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Repository\PLCategoryRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * «Базовое» дерево со статьями маркетплейса: статьи создаются на местах маркетплейсного шаблона,
 * выручка маркетплейса входит в «Выручку», его расходы — в EBITDA.
 */
final class ExtendPLTreeWithMarketplaceActionTest extends IntegrationTestCase
{
    public function testExtendsUniversalTreeOnceAndRewritesTotals(): void
    {
        $company = $this->universalCompany();
        self::assertTrue($this->facade()->needsMarketplacePlExtension((string) $company->getId()));

        $preview = $this->extend($company, true);
        self::assertCount(24, $preview->import->created);
        self::assertSame(44, $this->categoryCount($company), 'предпросмотр ничего не пишет');

        $result = $this->extend($company, false);

        self::assertCount(24, $result->import->created);
        self::assertEquals([
            new PLFormulaChange('REV_TOTAL', 'Выручка', 'REV_SALES_TOTAL', 'REV_SALES_TOTAL + REV_NOT_SPP_TOTAL'),
            new PLFormulaChange('EBITDA', 'EBITDA', 'GROSS_PROFIT + OVERHEAD_TOTAL', 'GROSS_PROFIT + PROMO_TOTAL + OPEX_WH_TOTAL + PRODUCT_INFRA_TOTAL + OVERHEAD_TOTAL'),
        ], $result->formulaChanges);
        self::assertSame(68, $this->categoryCount($company));
        self::assertSame([['UNIVERSAL', 'onboarding'], ['UNIVERSAL', 'mp_extension']], $this->connection->fetchAllNumeric('SELECT profile, source FROM pl_template_applications WHERE company_id = ? ORDER BY applied_at, source DESC', [(string) $company->getId()]));
        self::assertFalse($this->facade()->needsMarketplacePlExtension((string) $company->getId()));

        $this->em->clear();
        self::assertTrue($this->extend($company, false)->isEmpty(), 'повтор ничего не делает');
        self::assertCount(0, (self::getContainer()->get(ApplyDefaultPLTemplateAction::class))((string) $company->getId(), true, PLTemplateApplicationSource::SCREEN)->created, '«Базовый шаблон» по-прежнему применяется');
    }

    public function testMarketplaceRevenueAndCostsReachTotals(): void
    {
        $company = $this->extendedCompany();

        $values = $this->calculate($company, [
            'REV_SALES' => 1000.0,
            'REV_NOT_SPP' => 500.0,
            'REV_RETURNS' => -50.0,
            'REV_SPP_SALES' => 999.0,
            'COGS_MP_COMMISSION' => -100.0,
            'OPEX_WH_STORAGE' => -30.0,
            'PROMO_INTERNAL' => -20.0,
            'PRODUCT_INFRA_FF_SERVICES' => -10.0,
            'OVERHEAD_PROD_CERT' => -5.0,
        ]);

        self::assertSame(
            ['REV_TOTAL' => 1450.0, 'GROSS_PROFIT' => 1350.0, 'EBITDA' => 1285.0, 'NET_PROFIT' => 1285.0],
            array_intersect_key($values, array_flip(['REV_TOTAL', 'GROSS_PROFIT', 'EBITDA', 'NET_PROFIT'])),
        );
    }

    /**
     * Каждая строка ввода доходит до чистой прибыли ровно один раз, кроме дивидендов
     * (распределение прибыли) и выручки с СПП (справочно: СПП платит маркетплейс).
     */
    public function testEveryInputLineReachesNetProfitOnceExceptDividendsAndSpp(): void
    {
        $company = $this->extendedCompany();
        $leaves = $this->connection->fetchFirstColumn("SELECT code FROM pl_categories WHERE company_id = ? AND type = 'LEAF_INPUT'", [(string) $company->getId()]);
        $excluded = ['PROFIT_DISTR_DIVIDENDS', 'REV_SPP_SALES', 'REV_SPP_RETURNS'];
        self::assertSame([], array_values(array_diff($excluded, $leaves)));

        $values = $this->calculate($company, array_fill_keys($leaves, -1.0));

        self::assertSame(-(float) (\count($leaves) - \count($excluded)), $values['NET_PROFIT']);
    }

    public function testMarketplaceProfileIsRefused(): void
    {
        $company = $this->company(BusinessProfile::MARKETPLACE);
        self::assertSame(72, (self::getContainer()->get(EnsureDefaultPLTreeAction::class))((string) $company->getId()));

        $this->expectException(PLTreeExtensionException::class);
        $this->expectExceptionMessage('уже есть');

        $this->extend($company, true);
    }

    public function testTreeNotFromUniversalTemplateIsRefused(): void
    {
        $company = $this->company(BusinessProfile::UNIVERSAL);

        $this->expectException(PLTreeExtensionException::class);
        $this->expectExceptionMessage('не из шаблона «Базовый»');

        $this->extend($company, true);
    }

    public function testFormulaChangedByUserIsRefusedAndNothingWritten(): void
    {
        $company = $this->universalCompany();
        $this->connection->update('pl_categories', ['formula' => 'GROSS_PROFIT + OVERHEAD_TOTAL + 0'], ['company_id' => (string) $company->getId(), 'code' => 'EBITDA']);

        try {
            $this->extend($company, false);
            self::fail('Изменённую формулу нельзя перетирать');
        } catch (PLTreeExtensionException $e) {
            self::assertStringContainsString('Формулу «EBITDA» изменили вручную', $e->getMessage());
        }

        self::assertSame(44, $this->categoryCount($company));
        self::assertSame('REV_SALES_TOTAL', $this->connection->fetchOne("SELECT formula FROM pl_categories WHERE company_id = ? AND code = 'REV_TOTAL'", [(string) $company->getId()]));
    }

    /**
     * Регрессия (ревью): своя похожая статья («Расходы на продвижение» без кода) погасила бы группу
     * PROMO_TOTAL, а EBITDA всё равно сослалась бы на неё — отказ целиком.
     */
    public function testSimilarOwnLineRefusesWholeExtension(): void
    {
        $company = $this->universalCompany();
        $this->em->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Расходы на продвижение')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build());
        $this->em->flush();

        foreach ([true, false] as $dryRun) {
            try {
                $this->extend($company, $dryRun);
                self::fail('Похожая статья должна отклонять дополнение');
            } catch (PLTreeExtensionException $e) {
                self::assertStringContainsString('«Расходы на продвижение» ≈ ваша «Расходы на продвижение»', $e->getMessage());
            }
        }

        self::assertSame(45, $this->categoryCount($company));
        self::assertSame('GROSS_PROFIT + OVERHEAD_TOTAL', $this->connection->fetchOne("SELECT formula FROM pl_categories WHERE company_id = ? AND code = 'EBITDA'", [(string) $company->getId()]));
    }

    /**
     * Регрессия (ревью): своя группа с кодом OPEX_WH_TOTAL внутри операционных расходов попала бы в
     * EBITDA дважды — через OVERHEAD_TOTAL и напрямую.
     */
    public function testExistingGroupOutOfTemplatePlaceRefuses(): void
    {
        $company = $this->universalCompany();
        $overhead = $this->em->getRepository(PLCategory::class)->findOneBy(['company' => $company, 'code' => 'OVERHEAD_TOTAL']);
        self::assertInstanceOf(PLCategory::class, $overhead);
        $this->em->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withParent($overhead)->withName('Склад')->withCode('OPEX_WH_TOTAL')->withType(PLCategoryType::SUBTOTAL)->build());
        $this->em->flush();

        $this->expectException(PLTreeExtensionException::class);
        $this->expectExceptionMessage('посчиталась бы в итогах дважды');

        $this->extend($company, true);
    }

    private function extend(Company $company, bool $dryRun): ExtendPLTreeResult
    {
        return (self::getContainer()->get(ExtendPLTreeWithMarketplaceAction::class))((string) $company->getId(), $dryRun);
    }

    private function facade(): FinanceFacade
    {
        return self::getContainer()->get(FinanceFacade::class);
    }

    private function extendedCompany(): Company
    {
        $company = $this->universalCompany();
        $this->extend($company, false);
        $this->em->clear();
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }

    private function universalCompany(): Company
    {
        $company = $this->company(BusinessProfile::UNIVERSAL);
        self::assertSame(44, (self::getContainer()->get(EnsureDefaultPLTreeAction::class))((string) $company->getId()));

        return $company;
    }

    private function company(BusinessProfile $profile): Company
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $company->setBusinessProfile($profile);
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function categoryCount(Company $company): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pl_categories WHERE company_id = ?', [(string) $company->getId()]);
    }

    /**
     * @param array<string, float> $facts
     *
     * @return array<string, float>
     */
    private function calculate(Company $company, array $facts): array
    {
        $calculator = new PlReportCalculator(self::getContainer()->get(PLCategoryRepository::class), new class($facts) implements FactsProviderInterface {
            /** @param array<string, float> $facts */
            public function __construct(private array $facts)
            {
            }

            public function value(Company $company, PlReportPeriod $period, string $code, ProjectDirection|array|null $projectDirection = null, string|array|null $responsibilityCenterId = null): float
            {
                return $this->facts[$code] ?? 0.0;
            }
        });

        $result = $calculator->calculate($company, new PlReportPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), 'Октябрь'));
        self::assertSame([], $result->warnings, 'Формулы ссылаются на существующие коды');

        $values = [];
        foreach ($result->rows as $row) {
            $values[(string) $row->code] = round($row->rawValue, 4);
        }

        return $values;
    }
}
