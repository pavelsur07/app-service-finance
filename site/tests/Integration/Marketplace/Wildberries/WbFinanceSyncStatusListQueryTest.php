<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Wildberries;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Wildberries\Infrastructure\Query\WbFinanceSyncStatusListQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class WbFinanceSyncStatusListQueryTest extends IntegrationTestCase
{
    public function testFindMonthDaysReturnsOnlyDaysOfRequestedMonthForCompany(): void
    {
        [$company, $connection] = $this->createCompanyAndConnection(711);
        [$otherCompany, $otherConnection] = $this->createCompanyAndConnection(712);

        foreach (['2026-07-31', '2026-08-01', '2026-08-15', '2026-08-31', '2026-09-01'] as $date) {
            $this->em->persist($this->newStatus((string) $company->getId(), $connection->getId(), $date));
        }
        $this->em->persist($this->newStatus((string) $otherCompany->getId(), $otherConnection->getId(), '2026-08-10'));
        $this->em->flush();

        $query = self::getContainer()->get(WbFinanceSyncStatusListQuery::class);
        $rows = $query->findMonthDays((string) $company->getId(), new \DateTimeImmutable('2026-08-20 13:45'));

        self::assertSame(
            ['2026-08-31', '2026-08-15', '2026-08-01'],
            array_map(static fn (array $row): string => substr((string) $row['business_date'], 0, 10), $rows),
        );
    }

    /**
     * @return array{Company, MarketplaceConnection}
     */
    private function createCompanyAndConnection(int $index): array
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);

        $connection = new MarketplaceConnection(sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $index), $company, MarketplaceType::WILDBERRIES, MarketplaceConnectionType::SELLER);
        $connection->setApiKey('wb-token');
        $this->em->persist($connection);
        $this->em->flush();

        return [$company, $connection];
    }

    private function newStatus(string $companyId, string $connectionId, string $date): MarketplaceFinancialReportSyncStatus
    {
        return new MarketplaceFinancialReportSyncStatus(Uuid::uuid7()->toString(), $companyId, $connectionId, MarketplaceType::WILDBERRIES, 'sales_report', 'endpoint', new \DateTimeImmutable($date));
    }
}
