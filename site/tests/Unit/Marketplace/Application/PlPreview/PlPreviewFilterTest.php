<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\PlPreview;

use App\Marketplace\Application\PlPreview\PlPreviewFilter;
use App\Marketplace\Enum\MarketplaceType;
use PHPUnit\Framework\TestCase;

final class PlPreviewFilterTest extends TestCase
{
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new \DateTimeImmutable('2026-10-17 15:30:00');
    }

    public function testDefaultsToCurrentMonthToDateAllMarketplacesAllTags(): void
    {
        $filter = PlPreviewFilter::fromQuery([], $this->today);

        self::assertSame('2026-10-01', $filter->from->format('Y-m-d'));
        self::assertSame('2026-10-17', $filter->to->format('Y-m-d'));
        self::assertNull($filter->marketplace);
        self::assertNull($filter->tagIds);
        self::assertFalse($filter->showEmptyRows);
        self::assertSame(['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-17'], $filter->toQuery());
    }

    public function testReadsValidParameters(): void
    {
        $query = ['dateFrom' => '2026-01-01', 'dateTo' => '2026-03-31', 'marketplace' => 'ozon', 'tags' => ['a', 'b', 'a', ''], 'empty' => '1'];

        $filter = PlPreviewFilter::fromQuery($query, $this->today);

        self::assertSame('2026-01-01', $filter->from->format('Y-m-d'));
        self::assertSame('2026-03-31', $filter->to->format('Y-m-d'));
        self::assertSame(MarketplaceType::OZON, $filter->marketplace);
        self::assertSame(['a', 'b'], $filter->tagIds);
        self::assertTrue($filter->showEmptyRows);

        $command = $filter->toCommand('company-id');
        self::assertSame('company-id', $command->companyId);
        self::assertSame(['a', 'b'], $command->tagIds);
        self::assertSame(
            ['dateFrom' => '2026-01-01', 'dateTo' => '2026-03-31', 'marketplace' => 'ozon', 'tags_set' => '1', 'tags' => ['a', 'b'], 'empty' => '1'],
            $filter->toQuery(),
        );
    }

    public function testGarbageFallsBackToDefaults(): void
    {
        $filter = PlPreviewFilter::fromQuery(
            ['dateFrom' => '2026-02-30', 'dateTo' => ['x'], 'marketplace' => 'amazon', 'tags' => 'zima', 'empty' => 'yes'],
            $this->today,
        );

        self::assertSame('2026-10-01', $filter->from->format('Y-m-d'));
        self::assertSame('2026-10-17', $filter->to->format('Y-m-d'));
        self::assertNull($filter->marketplace);
        self::assertNull($filter->tagIds);
        self::assertFalse($filter->showEmptyRows);
    }

    public function testEmptyTagListMeansNoTagColumns(): void
    {
        self::assertSame([], PlPreviewFilter::fromQuery(['tags' => ['']], $this->today)->tagIds);
        // Все флажки сняты: браузер не шлёт tags[], только маркер формы.
        self::assertSame([], PlPreviewFilter::fromQuery(['tags_set' => '1'], $this->today)->tagIds);
    }
}
