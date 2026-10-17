<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\PlPreview;

use App\Company\Entity\ProjectDirection;
use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Application\Action\BuildMarketplacePlPreviewAction;
use App\Marketplace\Application\Command\BuildMarketplacePlPreviewCommand;
use App\Marketplace\Application\DTO\MarketplacePlPreviewReport;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Entity\MarketplaceListingTag;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewColumnKind;
use App\Marketplace\Enum\PlPreviewCostStatus;
use App\Marketplace\Exception\MarketplacePlPreviewPeriodException;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Marketplace\Infrastructure\Query\PlPreviewAdLedgerQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewCostCoverageQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewFactsQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewSalesCoverageQuery;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use App\MarketplaceAds\Entity\AdDocument;
use App\MarketplaceAds\Facade\MarketplaceAdsFacade;
use App\MarketplaceAds\Facade\PlPreviewAdSpendProvider;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Builders\MarketplaceAds\AdDocumentBuilder;
use App\Tests\Builders\MarketplaceAds\AdDocumentLineBuilder;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
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
        );
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

    private function wbPromotion(bool $includeInPl, string $ledger): void
    {
        $category = new MarketplaceCostCategory(Uuid::uuid4()->toString(), $this->fixture->company, MarketplaceType::WILDBERRIES);
        $category->setName('WB Продвижение');
        $category->setCode('wb_okazanie_uslug_wb_prodvizhenie');
        $this->em->persist($category);
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid4()->toString(), $this->companyId(), $category, $this->fixture->costs->getId(), $includeInPl));
        $cost = new MarketplaceCost(Uuid::uuid4()->toString(), $this->fixture->company, MarketplaceType::WILDBERRIES, $category);
        $cost->setAmount($ledger);
        $cost->setCostDate(new \DateTimeImmutable('2026-01-18'));
        $cost->setOperationType(MarketplaceCostOperationType::CHARGE);
        $this->em->persist($cost);
        $this->em->flush();
    }

    /**
     * @param array<string, string> $byListing имя листинга фикстуры → расход
     */
    private function adStatistics(array $byListing, string $unallocated): void
    {
        $raw = AdRawDocumentBuilder::aRawDocument()->withIndex(421)->withCompanyId($this->companyId())->withMarketplace(MarketplaceType::WILDBERRIES)->build();
        $this->em->persist($raw);
        $index = 0;
        foreach ($byListing as $name => $amount) {
            $document = $this->adDocument(++$index, $amount, $raw->getId());
            $listing = 'wbA' === $name ? $this->fixture->wbA : $this->fixture->wbB;
            $this->em->persist(AdDocumentLineBuilder::aLine()->withIndex(420 + $index)->withAdDocument($document)->withListingId($listing->getId())->withCost($amount)->build());
        }
        if (0 !== bccomp($unallocated, '0', 2)) {
            $this->adDocument(++$index, $unallocated, $raw->getId());
        }
        $this->em->flush();
    }

    private function adDocument(int $index, string $cost, string $rawId): AdDocument
    {
        $document = AdDocumentBuilder::aDocument()
            ->withIndex(420 + $index)
            ->withCompanyId($this->companyId())
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withReportDate(new \DateTimeImmutable('2026-01-15'))
            ->withTotalCost($cost)
            ->withAdRawDocumentId($rawId)
            ->build();
        $this->em->persist($document);

        return $document;
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
