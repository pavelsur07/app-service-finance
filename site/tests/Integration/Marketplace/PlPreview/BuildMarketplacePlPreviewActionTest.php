<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\PlPreview;

use App\Company\Entity\ProjectDirection;
use App\Company\Facade\CompanyFacade;
use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Application\Action\BuildMarketplacePlPreviewAction;
use App\Marketplace\Application\Command\BuildMarketplacePlPreviewCommand;
use App\Marketplace\Application\DTO\MarketplacePlPreviewReport;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceListingTag;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AdSpendKind;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewColumnKind;
use App\Marketplace\Enum\PlPreviewColumnMode;
use App\Marketplace\Enum\PlPreviewCostStatus;
use App\Marketplace\Exception\MarketplacePlPreviewPeriodException;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Marketplace\Infrastructure\Query\OzonPlMappingWarningsQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewAdLedgerQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewCostCoverageQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewFactsQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewSalesCoverageQuery;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use App\MarketplaceAds\Facade\MarketplaceAdsFacade;
use App\MarketplaceAds\Facade\PlPreviewAdSpendProvider;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class BuildMarketplacePlPreviewActionTest extends IntegrationTestCase
{
    private PlPreviewFixture $fixture;
    private MarketplaceListingTag $winter;
    private MarketplaceListingTag $summer;
    private BuildMarketplacePlPreviewAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new PlPreviewFixture($this->em, 421);
        $companyId = $this->companyId();

        $this->winter = MarketplaceListingTagBuilder::aTag()->forCompanyId($companyId)->withName('Зима')->build();
        $this->summer = MarketplaceListingTagBuilder::aTag()->forCompanyId($companyId)->withName('Лето')->build();
        $this->em->persist($this->winter);
        $this->em->persist($this->summer);
        $this->em->flush();

        $container = self::getContainer();
        $assignments = $container->get(ListingTagAssignmentRepository::class);
        // wbA — в двух тегах, ozA — в одном, wbB без тегов.
        $assignments->assign($companyId, [$this->fixture->wbA->getId(), $this->fixture->ozA->getId()], $this->winter->getId());
        $assignments->assign($companyId, [$this->fixture->wbA->getId()], $this->summer->getId());

        $connection = $this->em->getConnection();
        $this->action = new BuildMarketplacePlPreviewAction(
            new PlPreviewFactsQuery($connection),
            new PlPreviewCostCoverageQuery($connection),
            new PlPreviewSalesCoverageQuery($connection),
            $container->get(MarketplaceListingTagRepository::class),
            $assignments,
            $container->get(MarketplaceConnectionRepository::class),
            $container->get(FinanceFacade::class),
            new PlPreviewAdLedgerQuery($connection),
            new PlPreviewAdSpendProvider($container->get(MarketplaceAdsFacade::class)),
            new OzonPlMappingWarningsQuery($connection),
            $container->get(CompanyFacade::class),
        );
    }

    public function testLegacyOzonMappingWarnsWithoutChangingAmounts(): void
    {
        $legacy = new MarketplaceSaleMapping(
            Uuid::uuid4()->toString(), $this->fixture->company, MarketplaceType::OZON,
            AmountSource::SALE_REVENUE, $this->fixture->realization,
        );
        $this->em->persist($legacy);
        $this->em->flush();

        $report = $this->build();
        self::assertStringContainsString('sale_revenue', implode(' ', $report->warnings));
        self::assertStringContainsString('sale_realization', implode(' ', $report->warnings));
        self::assertSame(300.0, $this->values($report)['REAL']['total']);
        foreach ([MarketplaceType::WILDBERRIES, MarketplaceType::YANDEX_MARKET, MarketplaceType::SBER_MEGAMARKET] as $marketplace) {
            $other = ($this->action)(new BuildMarketplacePlPreviewCommand(
                $this->companyId(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'), $marketplace,
            ));
            self::assertStringNotContainsString('sale_revenue', implode(' ', $other->warnings), $marketplace->value);
        }
        $legacy->setIsActive(false);
        $this->em->flush();
        self::assertStringNotContainsString('sale_revenue', implode(' ', $this->build()->warnings));
    }

    public function testMissingRealizationIsExplicitlyReported(): void
    {
        $sale = MarketplaceSaleBuilder::aSale()
            ->forCompany($this->fixture->company)->forListing($this->fixture->ozA)
            ->withMarketplace(MarketplaceType::OZON)->withSaleDate(new \DateTimeImmutable('2026-02-10'))->build();
        $this->em->persist($sale);
        $this->em->flush();
        $report = ($this->action)(new BuildMarketplacePlPreviewCommand(
            $this->companyId(), new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28'), MarketplaceType::OZON,
        ));
        self::assertStringContainsString('2026-02', implode(' ', $report->warnings));
        self::assertStringContainsString('не загружена или не обработана', implode(' ', $report->warnings));
    }

    public function testColumnsByTagCommonAndTotal(): void
    {
        $report = $this->build();

        self::assertSame(
            [['tag:'.$this->winter->getId(), 'Зима', PlPreviewColumnKind::TAG], ['tag:'.$this->summer->getId(), 'Лето', PlPreviewColumnKind::TAG], ['common', 'Общие / без тега', PlPreviewColumnKind::COMMON], ['total', 'Итого', PlPreviewColumnKind::TOTAL]],
            array_map(static fn ($c): array => [$c->key, $c->label, $c->kind], $report->columns),
        );

        $winter = 'tag:'.$this->winter->getId();
        $summer = 'tag:'.$this->summer->getId();
        self::assertSame([
            'COST' => [$winter => -70.0, $summer => -70.0, 'common' => -120.0, 'total' => -190.0],
            'REAL' => [$winter => 200.0, $summer => 0.0, 'common' => 100.0, 'total' => 300.0],
            'RET' => [$winter => -200.0, $summer => -200.0, 'common' => 0.0, 'total' => -200.0],
            'REV' => [$winter => 1000.0, $summer => 1000.0, 'common' => 300.0, 'total' => 1300.0],
        ], $this->values($report));
        self::assertTrue($report->tagsOverlap, 'wbA в двух выбранных тегах.');
        self::assertSame([], $report->unplaced);
    }

    public function testTagFilterKeepsCommonColumnStable(): void
    {
        $report = $this->build([$this->summer->getId(), Uuid::uuid4()->toString()]);

        self::assertSame(['tag:'.$this->summer->getId(), 'common', 'total'], array_map(static fn ($c): string => $c->key, $report->columns));
        // ozA (только «Зима») не уходит в «Общие»: колонка не зависит от фильтра.
        self::assertSame(100.0, $this->values($report)['REAL']['common']);
        self::assertFalse($report->tagsOverlap);
    }

    public function testBlocksAndReconciliation(): void
    {
        $report = $this->build();

        self::assertSame(['wb_excluded'], array_map(static fn ($r): ?string => $r->costCategoryCode, $report->costsWithStatus(PlPreviewCostStatus::EXCLUDED)));
        self::assertCount(3, $report->costsWithStatus(PlPreviewCostStatus::UNMAPPED));
        self::assertSame('1130.00', $report->costTotal);
        self::assertSame('0.00', $report->costReconciliationDelta);
        self::assertSame([], $report->salesWithoutMapping());
    }

    public function testWarnsAboutConnectionWithoutProject(): void
    {
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $this->fixture->company, 'Маркетплейсы');
        $wb = new MarketplaceConnection(Uuid::uuid4()->toString(), $this->fixture->company, MarketplaceType::WILDBERRIES);
        $wb->setApiKey('test-key');
        $wb->setProjectDirectionId($project->getId());
        $this->em->persist($project);
        $this->em->persist($wb);
        $this->em->flush();

        $warnings = array_values(array_filter($this->build()->warnings, static fn (string $w): bool => str_contains($w, 'проект')));

        self::assertCount(1, $warnings);
        self::assertStringStartsWith(MarketplaceType::OZON->getDisplayName().':', $warnings[0]);
    }

    public function testWarnsWhenPeriodCutsOzonRealization(): void
    {
        $report = ($this->action)(new BuildMarketplacePlPreviewCommand(
            $this->companyId(),
            new \DateTimeImmutable('2026-01-15'),
            new \DateTimeImmutable('2026-02-14'),
            MarketplaceType::OZON,
        ));

        $realization = array_values(array_filter($report->warnings, static fn (string $w): bool => str_starts_with($w, 'Реализация Ozon')));
        // Декабрьско-январский документ (15.12–14.01) с периодом не пересекается — о нём молчим.
        self::assertSame([
            'Реализация Ozon за 01.01.2026 — 31.01.2026 входит в период не целиком и в предпросмотр не попала: выберите период, который содержит её полностью.',
        ], $realization);
        self::assertSame([], array_filter(
            $this->build()->warnings,
            static fn (string $w): bool => str_contains($w, '01.01.2026 — 31.01.2026'),
        ), 'Январь целиком — январская реализация в периоде.');
    }

    public function testAdsGoToTagsFromStatisticsAndTotalStaysLedger(): void
    {
        $this->wbPromotion(includeInPl: true, ledger: '1000.00');
        $this->adStatistics(['wbA' => '600.00', 'wbB' => '300.00'], unallocated: '50.00');

        $report = $this->build();

        $winter = 'tag:'.$this->winter->getId();
        $summer = 'tag:'.$this->summer->getId();
        // Логистика: wbA −70, wbB −50, без листинга −70. Реклама: wbA −600, wbB −300, остаток −100.
        self::assertSame(
            [$winter => -670.0, $summer => -670.0, 'common' => -520.0, 'total' => -1190.0],
            $this->values($report)['COST'],
        );
        self::assertCount(1, $report->adReconciliation);
        $ads = $report->adReconciliation[0];
        self::assertSame(MarketplaceType::WILDBERRIES, $ads->marketplace);
        self::assertSame(['1000.00', '950.00', '900.00', '50.00', '50.00'], [$ads->ledger, $ads->statistics, $ads->onListings, $ads->unallocated, $ads->difference]);
    }

    public function testAdsOutsideStatisticsAreOtherCategoriesOfTheSameArticle(): void
    {
        $this->wbPromotion(includeInPl: true, ledger: '1000.00');
        // Статья возвратов — другая статья, в рекламу вне статистики не попадает.
        $this->fixture->addWbCost('wb_other_article', $this->fixture->returns, '333.00');
        // Чужая компания со своей категорией в той же статье ничего не меняет.
        $this->fixture->addWbPromotion(true, '99999.00', [], $this->fixture->foreign);

        $ads = $this->build()->adReconciliation[0];

        // В статье COST кроме «Продвижения» — логистика 220 − сторно 30. Исключённая (500),
        // неразмеченная (400) и без правила (25) не входят, как и в саму статью.
        self::assertSame('190.00', $ads->outsideStatistics);
        // Учёт + реклама вне статистики = строка статьи в ОПиУ.
        self::assertSame(-1190.0, $this->values($this->build())['COST']['total']);
    }

    public function testOzonClicksAndPayPerOrderAreReconciledSeparately(): void
    {
        $this->fixture->addOzonAdCost('ozon_cpc', '100.00');
        // Оплата за заказ: 300 в учёте, 250 в отчёте по заказам — 50 вторых списаний остаются в «Общих».
        $this->fixture->addOzonAdCost('ozon_marketing_action', '300.00');
        $this->fixture->addOzonAdStatistics('42', '100.00', 1);
        $this->fixture->addOzonAdStatistics('search-promo', '250.00', 2);

        $report = $this->build([$this->winter->getId()]);

        self::assertSame(
            [
                [AdSpendKind::CLICKS, '100.00', '100.00', '100.00', '0.00'],
                [AdSpendKind::PAY_PER_ORDER, '300.00', '250.00', '250.00', '50.00'],
            ],
            array_map(static fn ($ads): array => [$ads->kind, $ads->ledger, $ads->statistics, $ads->onListings, $ads->difference], $report->adReconciliation),
        );
        self::assertSame('Ozon — оплата за заказ', $report->adReconciliation[1]->title());
        // «Вне статистики» у статьи одно — на первой строке Ozon, вторая не задваивает.
        self::assertSame('0.00', $report->adReconciliation[1]->outsideStatistics);

        $cost = $this->values($report)['COST'];
        // ozA в теге «Зима»: на нём клики и оплата за заказ из статистики; «Итого» — учёт.
        self::assertSame(-70.0 - 350.0, $cost['tag:'.$this->winter->getId()]);
        self::assertEqualsWithDelta($cost['total'], $cost['tag:'.$this->winter->getId()] + $cost['common'], 0.001);
        self::assertSame(-190.0 - 400.0, $cost['total']);
    }

    public function testColumnsAddUpToLedgerWithStornoNullTypeAndStatisticsAboveLedger(): void
    {
        // Учёт: начисление 1000, сторно 100, начисление без типа 50 → −950. Статистика 1500 > учёта.
        $this->fixture->addWbPromotion(true, '1000.00', [['100.00', MarketplaceCostOperationType::STORNO], ['50.00', null]]);
        $this->adStatistics(['wbA' => '1200.00', 'wbB' => '300.00'], unallocated: '0.00');
        // Чужая компания с той же категорией и большими суммами ничего не меняет.
        $this->fixture->addWbPromotion(true, '99999.00', [], $this->fixture->foreign);

        $report = $this->build([$this->winter->getId()]);
        $cost = $this->values($report)['COST'];

        // Логистика −190 + реклама по учёту −950.
        self::assertSame(-1140.0, $cost['total']);
        // Колонки без пересечения складываются в «Итого»: остаток учёт − статистика (+550) — в «Общих».
        self::assertEqualsWithDelta($cost['total'], $cost['tag:'.$this->winter->getId()] + $cost['common'], 0.001);
        self::assertSame(-1270.0, $cost['tag:'.$this->winter->getId()]);
        self::assertSame(['950.00', '1500.00', '-550.00'], [$report->adReconciliation[0]->ledger, $report->adReconciliation[0]->statistics, $report->adReconciliation[0]->difference]);
    }

    public function testWithoutStatisticsWholeLedgerStaysCommon(): void
    {
        $this->wbPromotion(includeInPl: true, ledger: '1000.00');

        $report = $this->build();

        self::assertSame(-70.0, $this->values($report)['COST']['tag:'.$this->winter->getId()]);
        self::assertSame(-1120.0, $this->values($report)['COST']['common']);
        self::assertSame(-1190.0, $this->values($report)['COST']['total']);
        self::assertSame(['1000.00', '0.00', '1000.00'], [$report->adReconciliation[0]->ledger, $report->adReconciliation[0]->statistics, $report->adReconciliation[0]->difference]);
    }

    public function testExcludedAdCategoryIgnoresStatistics(): void
    {
        $this->wbPromotion(includeInPl: false, ledger: '1000.00');
        $this->adStatistics(['wbA' => '600.00'], unallocated: '0.00');

        $report = $this->build();

        self::assertSame([], $report->adReconciliation);
        self::assertSame(-70.0, $this->values($report)['COST']['tag:'.$this->winter->getId()]);
    }

    public function testMarketplaceFilterLimitsStatistics(): void
    {
        $this->wbPromotion(includeInPl: true, ledger: '1000.00');
        $this->adStatistics(['wbA' => '600.00'], unallocated: '0.00');

        $report = ($this->action)(new BuildMarketplacePlPreviewCommand(
            $this->companyId(),
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            MarketplaceType::OZON,
        ));

        self::assertSame([], $report->adReconciliation);
    }

    public function testWritesNothing(): void
    {
        $this->build();

        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM documents'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM pl_daily_totals'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM marketplace_sales WHERE document_id IS NOT NULL'));
    }

    public function testEmptyPeriodGivesZeros(): void
    {
        $report = ($this->action)(new BuildMarketplacePlPreviewCommand(
            $this->companyId(),
            new \DateTimeImmutable('2026-05-01'),
            new \DateTimeImmutable('2026-05-31'),
        ));

        foreach ($report->rows as $row) {
            self::assertSame(0.0, $row->values['total']);
        }
        self::assertSame('0.00', $report->costTotal);
        self::assertSame([], $report->warnings);
    }

    public function testRejectsReversedPeriod(): void
    {
        $this->expectException(MarketplacePlPreviewPeriodException::class);

        ($this->action)(new BuildMarketplacePlPreviewCommand($this->companyId(), new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-01-31')));
    }

    public function testRejectsPeriodLongerThanAYear(): void
    {
        $this->expectException(MarketplacePlPreviewPeriodException::class);

        ($this->action)(new BuildMarketplacePlPreviewCommand($this->companyId(), new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-01-02')));
    }

    public function testProjectColumnsTakeRuleProjectThenConnectionProject(): void
    {
        $wb = $this->project('Вайлдберриз');
        $child = $this->project('Дочерний', $wb);
        $ozon = $this->project('Озон');
        $this->connection(MarketplaceType::WILDBERRIES, $wb);
        $this->ozonMappingsProject($ozon);
        $this->em->flush();

        $report = $this->buildProjects();

        self::assertSame(PlPreviewColumnMode::PROJECTS, $report->mode);
        self::assertSame(
            [['project:'.$wb->getId(), 'Вайлдберриз', PlPreviewColumnKind::PROJECT], ['project:'.$child->getId(), 'Дочерний', PlPreviewColumnKind::PROJECT], ['project:'.$ozon->getId(), 'Озон', PlPreviewColumnKind::PROJECT], ['total', 'Итого', PlPreviewColumnKind::TOTAL]],
            array_map(static fn ($c): array => [$c->key, $c->label, $c->kind], $report->columns),
            '«Без проекта» пуста — колонки нет; дочерний проект с отступом, без свёртки сумм в родителя.',
        );
        self::assertSame('— Дочерний', $report->columns[1]->indentedLabel());
        $wbKey = 'project:'.$wb->getId();
        $childKey = 'project:'.$child->getId();
        $ozonKey = 'project:'.$ozon->getId();
        // WB: правила без проекта → проект подключения, затраты — всегда проект подключения.
        self::assertSame([
            'COST' => [$wbKey => -190.0, $childKey => 0.0, $ozonKey => 0.0, 'total' => -190.0],
            'REAL' => [$wbKey => 0.0, $childKey => 0.0, $ozonKey => 300.0, 'total' => 300.0],
            'RET' => [$wbKey => -200.0, $childKey => 0.0, $ozonKey => 0.0, 'total' => -200.0],
            'REV' => [$wbKey => 1300.0, $childKey => 0.0, $ozonKey => 0.0, 'total' => 1300.0],
        ], $this->values($report));
        self::assertFalse($report->tagsOverlap);
    }

    public function testRuleProjectWinsOverConnectionProject(): void
    {
        $wb = $this->project('Вайлдберриз');
        $rule = $this->project('Правило');
        $this->connection(MarketplaceType::WILDBERRIES, $wb);
        foreach ($this->mappings(MarketplaceType::WILDBERRIES) as $mapping) {
            $mapping->setProjectDirection($rule);
        }
        $this->em->flush();

        $values = $this->values($this->buildProjects());

        self::assertSame(1300.0, $values['REV']['project:'.$rule->getId()]);
        self::assertSame(0.0, $values['REV']['project:'.$wb->getId()]);
        self::assertSame(-190.0, $values['COST']['project:'.$wb->getId()], 'У затрат проекта правила нет.');
    }

    public function testAmountsWithoutProjectGoToNoProjectColumnAndColumnsAddUpToTotal(): void
    {
        $ozon = $this->project('Озон');
        $this->ozonMappingsProject($ozon);
        $this->em->flush();

        $report = $this->buildProjects();

        self::assertSame(['project:'.$ozon->getId(), 'no_project', 'total'], array_map(static fn ($c): string => $c->key, $report->columns));
        self::assertSame('Без проекта', $report->columns[1]->label);
        self::assertSame(PlPreviewColumnKind::NO_PROJECT, $report->columns[1]->kind);
        foreach ($this->values($report) as $code => $row) {
            self::assertEqualsWithDelta($row['total'], $row['project:'.$ozon->getId()] + $row['no_project'], 0.001, $code);
        }
        self::assertSame(1300.0, $this->values($report)['REV']['no_project']);
    }

    public function testProjectFilterDropsForeignAndUnselectedProjects(): void
    {
        $wb = $this->project('Вайлдберриз');
        $ozon = $this->project('Озон');
        $foreign = new ProjectDirection(Uuid::uuid4()->toString(), $this->fixture->foreign, 'Чужой');
        $this->em->persist($foreign);
        $this->connection(MarketplaceType::WILDBERRIES, $wb);
        $this->ozonMappingsProject($ozon);
        $this->em->flush();

        $report = $this->buildProjects([(string) $ozon->getId(), (string) $foreign->getId()]);

        self::assertSame(['project:'.$ozon->getId(), 'total'], array_map(static fn ($c): string => $c->key, $report->columns), 'Сумма невыбранного проекта не уходит в «Без проекта».');
    }

    public function testUnknownConnectionProjectGoesToNoProjectWithWarning(): void
    {
        $ozon = $this->project('Озон');
        $this->ozonMappingsProject($ozon);
        $stale = new MarketplaceConnection(Uuid::uuid4()->toString(), $this->fixture->company, MarketplaceType::WILDBERRIES);
        $stale->setApiKey('test-key');
        $stale->setProjectDirectionId(Uuid::uuid4()->toString());
        $this->em->persist($stale);
        $this->em->flush();

        $report = $this->buildProjects();

        self::assertSame(['project:'.$ozon->getId(), 'no_project', 'total'], array_map(static fn ($c): string => $c->key, $report->columns));
        self::assertSame(1300.0, $this->values($report)['REV']['no_project'], 'Сумма на несуществующем проекте не пропадает.');
        $warnings = array_values(array_filter($report->warnings, static fn (string $w): bool => str_contains($w, 'не найден среди проектов')));
        self::assertCount(1, $warnings);
        self::assertStringStartsWith(MarketplaceType::WILDBERRIES->getDisplayName().':', $warnings[0]);
    }

    public function testProjectModeWithMarketplaceFilter(): void
    {
        $wb = $this->project('Вайлдберриз');
        $this->connection(MarketplaceType::WILDBERRIES, $wb);
        $this->em->flush();

        $report = ($this->action)(new BuildMarketplacePlPreviewCommand(
            $this->companyId(),
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            MarketplaceType::WILDBERRIES,
            null,
            PlPreviewColumnMode::PROJECTS,
        ));

        self::assertSame(['project:'.$wb->getId(), 'total'], array_map(static fn ($c): string => $c->key, $report->columns), 'Реализации Ozon нет — нет и «Без проекта».');
        self::assertSame(1300.0, $this->values($report)['REV']['project:'.$wb->getId()]);
    }

    public function testAdsGoToConnectionProjectFromLedgerInProjectMode(): void
    {
        $wb = $this->project('Вайлдберриз');
        $this->connection(MarketplaceType::WILDBERRIES, $wb);
        $this->em->flush();
        $this->wbPromotion(includeInPl: true, ledger: '1000.00');
        $this->adStatistics(['wbA' => '600.00', 'wbB' => '300.00'], unallocated: '50.00');

        $report = $this->buildProjects();

        self::assertSame(['project:'.$wb->getId() => -1190.0, 'no_project' => 0.0, 'total' => -1190.0], $this->values($report)['COST']);
        self::assertCount(1, $report->adReconciliation, 'Сверка учёта со статистикой — в обоих режимах.');
    }

    private function project(string $name, ?ProjectDirection $parent = null): ProjectDirection
    {
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $this->fixture->company, $name);
        $project->setParent($parent);
        $this->em->persist($project);

        return $project;
    }

    private function connection(MarketplaceType $marketplace, ProjectDirection $project): void
    {
        $connection = new MarketplaceConnection(Uuid::uuid4()->toString(), $this->fixture->company, $marketplace);
        $connection->setApiKey('test-key');
        $connection->setProjectDirectionId($project->getId());
        $this->em->persist($connection);
    }

    private function ozonMappingsProject(ProjectDirection $project): void
    {
        foreach ($this->mappings(MarketplaceType::OZON) as $mapping) {
            $mapping->setProjectDirection($project);
        }
    }

    /**
     * @return list<MarketplaceSaleMapping>
     */
    private function mappings(MarketplaceType $marketplace): array
    {
        /** @var list<MarketplaceSaleMapping> $mappings */
        $mappings = $this->em->getRepository(MarketplaceSaleMapping::class)->findBy([
            'company' => $this->fixture->company,
            'marketplace' => $marketplace,
        ]);

        return $mappings;
    }

    /**
     * @param list<string>|null $projectIds
     */
    private function buildProjects(?array $projectIds = null): MarketplacePlPreviewReport
    {
        return ($this->action)(new BuildMarketplacePlPreviewCommand(
            $this->companyId(),
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            null,
            null,
            PlPreviewColumnMode::PROJECTS,
            $projectIds,
        ));
    }

    private function wbPromotion(bool $includeInPl, string $ledger): void
    {
        $this->fixture->addWbPromotion($includeInPl, $ledger);
    }

    /**
     * @param array<string, string> $byListing имя листинга фикстуры → расход
     */
    private function adStatistics(array $byListing, string $unallocated): void
    {
        $this->fixture->addWbAdStatistics($byListing, $unallocated);
    }

    /**
     * @param list<string>|null $tagIds
     */
    private function build(?array $tagIds = null): MarketplacePlPreviewReport
    {
        return ($this->action)(new BuildMarketplacePlPreviewCommand(
            $this->companyId(),
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            null,
            $tagIds,
        ));
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function values(MarketplacePlPreviewReport $report): array
    {
        $values = [];
        foreach ($report->rows as $row) {
            $values[(string) $row->code] = $row->values;
        }
        ksort($values);

        return $values;
    }

    private function companyId(): string
    {
        return (string) $this->fixture->company->getId();
    }
}
