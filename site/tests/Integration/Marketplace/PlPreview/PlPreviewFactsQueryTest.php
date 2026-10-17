<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\PlPreview;

use App\Marketplace\Application\DTO\PlPreviewFactDTO;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\PlPreviewFactsQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedCostsQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedRealizationQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedRealizationReturnQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedReturnsQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedSalesQuery;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class PlPreviewFactsQueryTest extends IntegrationTestCase
{
    private PlPreviewFixture $fixture;
    private PlPreviewFactsQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new PlPreviewFixture($this->em, 401);
        $this->query = new PlPreviewFactsQuery($this->em->getConnection());
    }

    public function testAmountsByCategoryAndListing(): void
    {
        $f = $this->fixture;

        self::assertEquals([
            $this->key('REV', $f->wbA->getId()) => 1000.0,
            $this->key('REV', $f->wbB->getId()) => 300.0,
            $this->key('RET', $f->wbA->getId()) => -200.0,
            $this->key('COST', $f->wbA->getId()) => -70.0,
            $this->key('COST', $f->wbB->getId()) => -50.0,
            $this->key('COST', null) => -70.0,
            $this->key('REAL', $f->ozA->getId()) => 200.0,
            $this->key('REAL', null) => 100.0,
        ], $this->map($this->fetch(null, true)));
    }

    public function testTotalsWithoutListingDimension(): void
    {
        self::assertEquals([
            $this->key('REV', null) => 1300.0,
            $this->key('RET', null) => -200.0,
            $this->key('COST', null) => -190.0,
            $this->key('REAL', null) => 300.0,
        ], $this->map($this->fetch(null, false)));
    }

    public function testMarketplaceFilter(): void
    {
        $wbCodes = array_map(
            fn (PlPreviewFactDTO $fact): string => $this->code($fact->plCategoryId),
            $this->fetch(MarketplaceType::WILDBERRIES, false),
        );
        sort($wbCodes);
        self::assertSame(['COST', 'RET', 'REV'], $wbCodes);
        self::assertEquals(
            [$this->key('REAL', null) => 300.0],
            $this->map($this->fetch(MarketplaceType::OZON, false)),
        );
    }

    public function testOtherPeriodGivesNothing(): void
    {
        self::assertSame([], $this->query->fetch(
            (string) $this->fixture->company->getId(),
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-31'),
            null,
            true,
        ));
    }

    /**
     * Итог по статье равен сумме строк документов, которые создало бы закрытие месяца:
     * те же Unprocessed*Query и знак is_negative, что в CloseMonthStageAction / FinanceFacade.
     */
    public function testTotalsMatchMonthClosePosting(): void
    {
        $companyId = (string) $this->fixture->company->getId();
        $container = self::getContainer();
        $rows = [];
        foreach (['wildberries', 'ozon'] as $marketplace) {
            array_push(
                $rows,
                ...$container->get(UnprocessedSalesQuery::class)->execute($companyId, $marketplace, '2026-01-01', '2026-01-31'),
                ...$container->get(UnprocessedReturnsQuery::class)->execute($companyId, $marketplace, '2026-01-01', '2026-01-31'),
                ...$container->get(UnprocessedCostsQuery::class)->execute($companyId, $marketplace, '2026-01-01', '2026-01-31'),
            );
        }
        array_push(
            $rows,
            ...$container->get(UnprocessedRealizationQuery::class)->execute($companyId, '2026-01-01', '2026-01-31'),
            ...$container->get(UnprocessedRealizationReturnQuery::class)->execute($companyId, '2026-01-01', '2026-01-31'),
        );

        $posting = [];
        foreach ($rows as $row) {
            $amount = (bool) $row['is_negative'] ? -(float) $row['total_amount'] : (float) $row['total_amount'];
            $code = $this->code((string) $row['pl_category_id']);
            $posting[$code] = ($posting[$code] ?? 0.0) + $amount;
        }

        $preview = [];
        foreach ($this->fetch(null, false) as $fact) {
            $preview[$this->code($fact->plCategoryId)] = (float) $fact->amount;
        }

        // Проведённая строка реализации в проведение уже не попадает, в предпросмотр — попадает.
        self::assertEqualsWithDelta($posting['REAL'] + 200.0, $preview['REAL'], 0.001);
        unset($posting['REAL'], $preview['REAL']);
        ksort($posting);
        ksort($preview);
        self::assertEqualsWithDelta($posting, $preview, 0.001);
    }

    /**
     * @return list<PlPreviewFactDTO>
     */
    private function fetch(?MarketplaceType $marketplace, bool $byListing): array
    {
        return $this->query->fetch(
            (string) $this->fixture->company->getId(),
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            $marketplace,
            $byListing,
        );
    }

    /**
     * @param list<PlPreviewFactDTO> $facts
     *
     * @return array<string, float>
     */
    private function map(array $facts): array
    {
        $map = [];
        foreach ($facts as $fact) {
            $map[$this->key($this->code($fact->plCategoryId), $fact->listingId)] = (float) $fact->amount;
        }
        ksort($map);

        return $map;
    }

    private function key(string $code, ?string $listingId): string
    {
        return $code.'|'.($listingId ?? '-');
    }

    private function code(string $plCategoryId): string
    {
        foreach ([$this->fixture->revenue, $this->fixture->returns, $this->fixture->costs, $this->fixture->realization] as $category) {
            if ($category->getId() === $plCategoryId) {
                return (string) $category->getCode();
            }
        }

        self::fail('Неизвестная статья '.$plCategoryId);
    }
}
