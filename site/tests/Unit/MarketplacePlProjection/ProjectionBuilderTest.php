<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplacePlProjection;

use App\MarketplaceOperations\Application\DTO\CanonPlSum;
use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplacePlProjection\Application\DTO\ProjectionReport;
use App\MarketplacePlProjection\Application\Service\ProjectionBuilder;
use App\MarketplacePlProjection\Domain\Rule;
use App\MarketplacePlProjection\Enum\ProjectionStatus;
use App\MarketplacePlProjection\Enum\RuleOrigin;
use PHPUnit\Framework\TestCase;

final class ProjectionBuilderTest extends TestCase
{
    public function testEveryKopeckOfCanonLandsInExactlyOneStatus(): void
    {
        $report = new ProjectionReport(ProjectionBuilder::project([
            new CanonPlSum('ozon', 'cab-1', '2026-11-01', OperationKind::SALE, 'ozon:field:sale_amount', 100000),
            new CanonPlSum('ozon', 'cab-2', '2026-11-02', OperationKind::SALE, 'ozon:field:sale_amount', 50000),
            new CanonPlSum('ozon', 'cab-1', '2026-11-01', OperationKind::COMMISSION, 'ozon:field:commission', -15001),
            new CanonPlSum('ozon', 'cab-1', '2026-11-01', OperationKind::SPP, 'ozon:field:bonus', 20000),
            new CanonPlSum('ozon', 'cab-1', '2026-11-01', OperationKind::LOGISTICS_TO, 'ozon:service:32', -5000),
            new CanonPlSum('wildberries', 'cab-3', '2026-11-01', OperationKind::SALE, 'wb:field:sale', 7000),
        ], [
            $this->rule(OperationKind::SALE, 'revenue'),
            $this->rule(OperationKind::COMMISSION, 'commission'),
            $this->rule(OperationKind::SPP, null),
        ]));

        self::assertSame(['revenue' => '1570.00', 'commission' => '-150.01'], $report->amountsByCategory());
        self::assertSame(157000 - 15001, $report->totalMinor(ProjectionStatus::MAPPED));
        self::assertSame(20000, $report->totalMinor(ProjectionStatus::EXCLUDED));
        self::assertSame(-5000, $report->totalMinor(ProjectionStatus::UNMAPPED));
        self::assertSame(157000 - 15001 + 20000 - 5000, $report->totalMinor());
        self::assertSame('ozon:service:32', $report->linesOf(ProjectionStatus::UNMAPPED)[0]->sourceType);
        self::assertCount(2, array_filter($report->lines, static fn ($l): bool => OperationKind::SALE === $l->kind), 'продажи двух кабинетов Ozon — одна строка, WB — своя');
    }

    public function testRuleChangedFromADateSplitsThePeriod(): void
    {
        $report = new ProjectionReport(ProjectionBuilder::project([
            new CanonPlSum('ozon', 'cab-1', '2026-10-31', OperationKind::STORAGE, 'ozon:service:46', -1000),
            new CanonPlSum('ozon', 'cab-1', '2026-11-01', OperationKind::STORAGE, 'ozon:service:46', -2000),
        ], [
            $this->rule(OperationKind::STORAGE, 'old'),
            $this->rule(OperationKind::STORAGE, 'new', '2026-11-01'),
        ]));

        self::assertSame(['old' => '-10.00', 'new' => '-20.00'], $report->amountsByCategory());
    }

    private function rule(OperationKind $kind, ?string $category, string $validFrom = Rule::BASE_DATE): Rule
    {
        return new Rule($kind, null, null, $category, RuleOrigin::PRESET, $validFrom);
    }
}
