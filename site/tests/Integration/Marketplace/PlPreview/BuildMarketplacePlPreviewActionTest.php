<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\PlPreview;

use App\Company\Entity\ProjectDirection;
use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Application\Action\BuildMarketplacePlPreviewAction;
use App\Marketplace\Application\Command\BuildMarketplacePlPreviewCommand;
use App\Marketplace\Application\DTO\MarketplacePlPreviewReport;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceListingTag;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewColumnKind;
use App\Marketplace\Enum\PlPreviewCostStatus;
use App\Marketplace\Exception\MarketplacePlPreviewPeriodException;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Marketplace\Infrastructure\Query\PlPreviewCostCoverageQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewFactsQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewSalesCoverageQuery;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
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

        $warnings = $this->build()->warnings;

        self::assertCount(1, $warnings);
        self::assertStringStartsWith(MarketplaceType::OZON->getDisplayName().':', $warnings[0]);
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
