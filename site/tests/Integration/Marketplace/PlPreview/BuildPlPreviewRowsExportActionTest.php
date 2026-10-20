<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\PlPreview;

use App\Marketplace\Application\Action\BuildMarketplacePlPreviewAction;
use App\Marketplace\Application\Action\BuildPlPreviewRowsExportAction;
use App\Marketplace\Application\Command\BuildMarketplacePlPreviewCommand;
use App\Marketplace\Application\DTO\MarketplacePlPreviewReport;
use App\Marketplace\Application\DTO\PlPreviewRowsExport;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplacePlPreviewPeriodException;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class BuildPlPreviewRowsExportActionTest extends IntegrationTestCase
{
    private PlPreviewFixture $fixture;
    private BuildPlPreviewRowsExportAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new PlPreviewFixture($this->em, 431);
        $container = self::getContainer();
        $this->action = new BuildPlPreviewRowsExportAction(
            $container->get(BuildMarketplacePlPreviewAction::class),
            $container->get(MarketplaceConnectionRepository::class),
        );
    }

    public function testChannelsWithDataComeInValueOrderAndAddUpToAll(): void
    {
        $export = $this->build();

        self::assertSame(['ozon', 'wildberries'], $this->channelValues($export));
        self::assertNotNull($export->all);
        self::assertNull($export->all->marketplace);
        self::assertSame('2026-01-01', $export->from->format('Y-m-d'));
        self::assertSame('2026-01-31', $export->to->format('Y-m-d'));

        $sum = [];
        foreach ($export->channels as $report) {
            foreach ($report->rows as $row) {
                $sum[$row->id] = ($sum[$row->id] ?? 0.0) + $row->values['total'];
            }
        }
        $all = [];
        foreach ($export->all->rows as $row) {
            $all[$row->id] = $row->values['total'];
        }
        self::assertEqualsWithDelta($all, $sum, 0.001);
        self::assertEqualsWithDelta(1300.0, $this->total($export->channels[1], 'REV'), 0.001);
        self::assertEqualsWithDelta(300.0, $this->total($export->channels[0], 'REAL'), 0.001);
    }

    public function testSellerConnectionWithoutDataGivesZeroChannelAndPerformanceDoesNot(): void
    {
        $this->connection(MarketplaceType::YANDEX_MARKET, MarketplaceConnectionType::SELLER);
        $this->connection(MarketplaceType::SBER_MEGAMARKET, MarketplaceConnectionType::PERFORMANCE);
        $this->em->flush();

        $export = $this->build();

        self::assertSame(['ozon', 'wildberries', 'yandex_market'], $this->channelValues($export));
        foreach ($export->channels[2]->rows as $row) {
            self::assertSame(0.0, $row->values['total']);
        }
    }

    public function testForeignCompanyConnectionDoesNotAddChannel(): void
    {
        $foreign = new MarketplaceConnection(Uuid::uuid4()->toString(), $this->fixture->foreign, MarketplaceType::YANDEX_MARKET);
        $foreign->setApiKey('test-key');
        $this->em->persist($foreign);
        $this->em->flush();

        self::assertSame(['ozon', 'wildberries'], $this->channelValues($this->build()));
    }

    public function testExplicitMarketplaceGivesOneChannelWithoutControl(): void
    {
        $export = $this->build(MarketplaceType::WILDBERRIES);

        self::assertSame(['wildberries'], $this->channelValues($export));
        self::assertNull($export->all);
    }

    public function testTagFilterReachesEveryChannel(): void
    {
        $tag = MarketplaceListingTagBuilder::aTag()->forCompanyId($this->companyId())->withName('Зима')->build();
        $this->em->persist($tag);
        $this->em->persist(MarketplaceListingTagBuilder::aTag()->forCompanyId($this->companyId())->withName('Лето')->build());
        $this->em->flush();

        $export = $this->build(null, [$tag->getId()]);

        self::assertNotNull($export->all);
        foreach ([$export->all, ...$export->channels] as $report) {
            self::assertSame(['tag:'.$tag->getId(), 'common', 'total'], array_map(static fn ($c): string => $c->key, $report->columns));
        }
    }

    public function testRejectsReversedPeriod(): void
    {
        $this->expectException(MarketplacePlPreviewPeriodException::class);

        ($this->action)(new BuildMarketplacePlPreviewCommand($this->companyId(), new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-01-31')));
    }

    public function testRejectsPeriodLongerThanAYearForExplicitChannel(): void
    {
        $this->expectException(MarketplacePlPreviewPeriodException::class);

        ($this->action)(new BuildMarketplacePlPreviewCommand($this->companyId(), new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-01-02'), MarketplaceType::OZON));
    }

    private function connection(MarketplaceType $marketplace, MarketplaceConnectionType $type): void
    {
        $connection = new MarketplaceConnection(Uuid::uuid4()->toString(), $this->fixture->company, $marketplace, $type);
        $connection->setApiKey('test-key');
        $this->em->persist($connection);
    }

    /**
     * @param list<string>|null $tagIds
     */
    private function build(?MarketplaceType $marketplace = null, ?array $tagIds = null): PlPreviewRowsExport
    {
        return ($this->action)(new BuildMarketplacePlPreviewCommand(
            $this->companyId(),
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            $marketplace,
            $tagIds,
        ));
    }

    /**
     * @return list<string>
     */
    private function channelValues(PlPreviewRowsExport $export): array
    {
        return array_map(static fn (MarketplacePlPreviewReport $r): string => (string) $r->marketplace?->value, $export->channels);
    }

    private function total(MarketplacePlPreviewReport $report, string $code): float
    {
        foreach ($report->rows as $row) {
            if ($row->code === $code) {
                return $row->values['total'];
            }
        }
        self::fail('Нет строки '.$code);
    }

    private function companyId(): string
    {
        return (string) $this->fixture->company->getId();
    }
}
