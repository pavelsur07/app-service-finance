<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application\Service;

use App\Marketplace\Application\Service\OzonRawDuplicatesCleanupPlanner;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class OzonRawDuplicatesCleanupPlannerTest extends IntegrationTestCase
{
    public function testCanonicalSelectionPrefersFreshCompletedDaily(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(401)->build();
        $this->em->persist($company);

        $day = new \DateTimeImmutable('2026-04-10');
        $dailyOld = MarketplaceRawDocumentBuilder::aDocument()->withIndex(401)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withDocumentType('sales_report')->withPeriod($day, $day)->withProcessingStatus('completed')->withSyncedAt(new \DateTimeImmutable('2026-04-10 09:00:00'))->build();
        $range = MarketplaceRawDocumentBuilder::aDocument()->withIndex(402)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withDocumentType('sales_report')->withPeriod(new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-30'))->withProcessingStatus('completed')->withSyncedAt(new \DateTimeImmutable('2026-04-11 10:00:00'))->build();
        $dailyFresh = MarketplaceRawDocumentBuilder::aDocument()->withIndex(403)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withDocumentType('sales_report')->withPeriod($day, $day)->withProcessingStatus('completed')->withSyncedAt(new \DateTimeImmutable('2026-04-12 11:00:00'))->build();
        $this->em->persist($dailyOld); $this->em->persist($range); $this->em->persist($dailyFresh);
        $this->em->flush();

        $planner = self::getContainer()->get(OzonRawDuplicatesCleanupPlanner::class);
        $plan = $planner->buildPlan($company->getId(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-30'));

        self::assertNotEmpty($plan->affectedDays);
        $dayPlan = $plan->affectedDays[0];
        self::assertSame($dailyFresh->getId(), $dayPlan->canonicalRawDocumentId);
    }
}
