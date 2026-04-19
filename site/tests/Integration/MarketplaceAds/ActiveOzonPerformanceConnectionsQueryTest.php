<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Infrastructure\Query\ActiveOzonPerformanceConnectionsQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class ActiveOzonPerformanceConnectionsQueryTest extends IntegrationTestCase
{
    private const COMPANY_PERF_ACTIVE_1 = '11111111-1111-1111-1111-0a0000000001';
    private const COMPANY_PERF_ACTIVE_2 = '11111111-1111-1111-1111-0a0000000002';
    private const COMPANY_PERF_INACTIVE = '11111111-1111-1111-1111-0a0000000003';
    private const COMPANY_SELLER_ONLY = '11111111-1111-1111-1111-0a0000000004';

    private ActiveOzonPerformanceConnectionsQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = self::getContainer()->get(ActiveOzonPerformanceConnectionsQuery::class);
    }

    public function testReturnsOnlyCompaniesWithActivePerformanceConnection(): void
    {
        $activeOne = $this->seedCompany(self::COMPANY_PERF_ACTIVE_1, 'perf-active-1@example.test', 1);
        $activeTwo = $this->seedCompany(self::COMPANY_PERF_ACTIVE_2, 'perf-active-2@example.test', 2);
        $inactive = $this->seedCompany(self::COMPANY_PERF_INACTIVE, 'perf-inactive@example.test', 3);
        $sellerOnly = $this->seedCompany(self::COMPANY_SELLER_ONLY, 'seller-only@example.test', 4);

        $this->em->persist($this->buildConnection($activeOne, MarketplaceType::OZON, MarketplaceConnectionType::PERFORMANCE, true));
        $this->em->persist($this->buildConnection($activeTwo, MarketplaceType::OZON, MarketplaceConnectionType::PERFORMANCE, true));
        $this->em->persist($this->buildConnection($inactive, MarketplaceType::OZON, MarketplaceConnectionType::PERFORMANCE, false));
        $this->em->persist($this->buildConnection($sellerOnly, MarketplaceType::OZON, MarketplaceConnectionType::SELLER, true));

        $this->em->flush();

        $companyIds = $this->query->getCompanyIds();

        self::assertContains(self::COMPANY_PERF_ACTIVE_1, $companyIds);
        self::assertContains(self::COMPANY_PERF_ACTIVE_2, $companyIds);
        self::assertNotContains(self::COMPANY_PERF_INACTIVE, $companyIds, 'Inactive performance connections must be excluded');
        self::assertNotContains(self::COMPANY_SELLER_ONLY, $companyIds, 'Seller-only companies must be excluded even for Ozon');
        self::assertCount(2, $companyIds);
    }

    public function testDoesNotReturnSellerOzonConnection(): void
    {
        $company = $this->seedCompany(self::COMPANY_SELLER_ONLY, 'seller-only@example.test', 1);
        $this->em->persist($this->buildConnection($company, MarketplaceType::OZON, MarketplaceConnectionType::SELLER, true));
        $this->em->flush();

        self::assertSame([], $this->query->getCompanyIds());
    }

    public function testDoesNotReturnInactivePerformanceConnection(): void
    {
        $company = $this->seedCompany(self::COMPANY_PERF_INACTIVE, 'perf-inactive@example.test', 1);
        $this->em->persist($this->buildConnection($company, MarketplaceType::OZON, MarketplaceConnectionType::PERFORMANCE, false));
        $this->em->flush();

        self::assertSame([], $this->query->getCompanyIds());
    }

    public function testReturnsEmptyListWhenNoConnections(): void
    {
        self::assertSame([], $this->query->getCompanyIds());
    }

    private function seedCompany(string $companyId, string $email, int $index): Company
    {
        $owner = UserBuilder::aUser()
            ->withIndex($index)
            ->withEmail($email)
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function buildConnection(
        Company $company,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $type,
        bool $isActive,
    ): MarketplaceConnection {
        $connection = new MarketplaceConnection(
            Uuid::uuid4()->toString(),
            $company,
            $marketplace,
            $type,
        );
        $connection->setApiKey('api-key');
        $connection->setClientId('client-id');
        $connection->setIsActive($isActive);

        return $connection;
    }
}
