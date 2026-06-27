<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Company\Entity\Company;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class OzonPerformanceLoadCommandTest extends IntegrationTestCase
{
    /**
     * @var list<string>
     */
    private const RESOURCE_TYPES = [
        OzonResourceType::PERFORMANCE_CAMPAIGNS,
        OzonResourceType::PERFORMANCE_SKU_CAMPAIGN_OBJECTS,
        OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS,
        OzonResourceType::PERFORMANCE_SKU_PRODUCT_STATISTICS,
        OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS,
        OzonResourceType::PERFORMANCE_EXPENSE_STATISTICS,
    ];

    public function testDailyLoadDryRunDoesNotCreateJobs(): void
    {
        $company = $this->seedCompany('11111111-1111-1111-1111-00000000a101', 9101);
        $this->seedPerformanceConnection($company, '77777777-7777-7777-7777-00000000a101');
        $this->em->flush();

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:ozon-performance:daily-load');
        $exit = $tester->execute([
            '--company-id' => $company->getId(),
            '--days-back' => '7',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Ozon Performance daily load', $tester->getDisplay());
        self::assertStringContainsString(OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS, $tester->getDisplay());
        self::assertSame(0, $this->syncJobCount($company->getId()));
        self::assertCount(0, $transport->getSent());
    }

    public function testDailyLoadExecuteDispatchesAllPerformanceResources(): void
    {
        $company = $this->seedCompany('11111111-1111-1111-1111-00000000a102', 9102);
        $connection = $this->seedPerformanceConnection($company, '77777777-7777-7777-7777-00000000a102');
        $this->em->flush();

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:ozon-performance:daily-load');
        $exit = $tester->execute([
            '--company-id' => $company->getId(),
            '--days-back' => '7',
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame($this->sortedResourceTypes(), $this->parentResourceTypes($company->getId()));
        self::assertSame(array_fill(0, count(self::RESOURCE_TYPES), $connection->getId()), $this->parentShopRefs($company->getId()));
        self::assertCount(count(self::RESOURCE_TYPES), $transport->getSent());
        foreach ($transport->getSent() as $envelope) {
            self::assertInstanceOf(RunSyncChunkMessage::class, $envelope->getMessage());
        }
    }

    public function testBackfillExecuteDispatchesAllPerformanceResourcesForExplicitPeriod(): void
    {
        $company = $this->seedCompany('11111111-1111-1111-1111-00000000a103', 9103);
        $this->seedPerformanceConnection($company, '77777777-7777-7777-7777-00000000a103');
        $this->em->flush();

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:ozon-performance:backfill');
        $exit = $tester->execute([
            '--company-id' => $company->getId(),
            '--from' => '2026-06-01',
            '--to' => '2026-06-07',
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame($this->sortedResourceTypes(), $this->parentResourceTypes($company->getId()));
        self::assertCount(count(self::RESOURCE_TYPES), $transport->getSent());
    }

    private function seedCompany(string $companyId, int $ownerIndex): Company
    {
        $owner = UserBuilder::aUser()
            ->withIndex($ownerIndex)
            ->withEmail(sprintf('%s@example.test', str_replace('-', '', $companyId)))
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function seedPerformanceConnection(Company $company, string $connectionId): MarketplaceConnection
    {
        $connection = new MarketplaceConnection(
            $connectionId,
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );
        $connection->setApiKey('performance-secret');
        $connection->setClientId('performance-client');
        $connection->setIsActive(true);

        $this->em->persist($connection);

        return $connection;
    }

    /**
     * @return list<string>
     */
    private function parentResourceTypes(string $companyId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT resource_type
             FROM ingest_sync_jobs
             WHERE company_id = :companyId AND parent_job_id IS NULL
             ORDER BY resource_type',
            ['companyId' => $companyId],
        );

        return array_map(static fn (mixed $value): string => (string) $value, $rows);
    }

    /**
     * @return list<string>
     */
    private function parentShopRefs(string $companyId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT shop_ref
             FROM ingest_sync_jobs
             WHERE company_id = :companyId AND parent_job_id IS NULL
             ORDER BY resource_type',
            ['companyId' => $companyId],
        );

        return array_map(static fn (mixed $value): string => (string) $value, $rows);
    }

    private function syncJobCount(string $companyId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_sync_jobs WHERE company_id = :companyId',
            ['companyId' => $companyId],
        );
    }

    /**
     * @return list<string>
     */
    private function sortedResourceTypes(): array
    {
        $resourceTypes = self::RESOURCE_TYPES;
        sort($resourceTypes);

        return $resourceTypes;
    }

    private function tester(string $commandName): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find($commandName));
    }

    private function getIngestFetchTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.ingest_fetch');

        return $transport;
    }
}
