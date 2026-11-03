<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Wildberries;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * rrd_id отчёта WB перерос int4 (03.11.2026 на проде — 3 137 233 657 015): курсор
 * пагинации в INT-колонке ронял сохранение статуса, и день навсегда оставался в queued.
 */
final class WbSyncStatusNextRrdIdBigintTest extends IntegrationTestCase
{
    private const RRD_ID_ABOVE_INT4 = 3_137_233_657_015;

    public function testCursorAboveInt4IsPersistedAndReadBack(): void
    {
        $user = UserBuilder::aUser()->withIndex(731)->build();
        $company = CompanyBuilder::aCompany()->withIndex(731)->withOwner($user)->build();
        $connection = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES, MarketplaceConnectionType::SELLER);
        $connection->setApiKey('wb-token');
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($connection);

        $status = new MarketplaceFinancialReportSyncStatus(
            Uuid::uuid7()->toString(),
            (string) $company->getId(),
            $connection->getId(),
            MarketplaceType::WILDBERRIES,
            'sales_report',
            'wildberries::finance-sales-reports-detailed',
            new \DateTimeImmutable('2026-10-26'),
        );
        $status->scheduleNextRetryAt(new \DateTimeImmutable('2026-11-03 05:30'), null, self::RRD_ID_ABOVE_INT4);
        $this->em->persist($status);
        $this->em->flush();
        $statusId = $status->getId();
        $this->em->clear();

        $reloaded = $this->em->find(MarketplaceFinancialReportSyncStatus::class, $statusId);

        self::assertNotNull($reloaded);
        self::assertSame(self::RRD_ID_ABOVE_INT4, $reloaded->getNextRrdId());
    }
}
