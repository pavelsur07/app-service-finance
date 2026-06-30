<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Company\Entity\Company;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class OzonAccrualRollingRefreshCommandTest extends IntegrationTestCase
{
    public function testDryRunDoesNotCreateJobsOrDispatchMessages(): void
    {
        $company = $this->seedCompany(2101);
        $this->seedConnection($company, '77777777-7777-7777-7777-000000002101');

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:ozon-accrual:rolling-refresh');
        $exit = $tester->execute([
            '--company-id' => $company->getId(),
            '--days-back' => '14',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('dry-run', $tester->getDisplay());
        self::assertSame(0, $this->backfillJobCount($company->getId()));
        self::assertCount(0, $transport->getSent());
    }

    public function testExecuteStartsAccrualBackfillOnlyForOzonSellerConnections(): void
    {
        $ozonCompany = $this->seedCompany(2102);
        $ozonConnection = $this->seedConnection($ozonCompany, '77777777-7777-7777-7777-000000002102');

        $wbCompany = $this->seedCompany(2103);
        $this->seedConnection($wbCompany, '77777777-7777-7777-7777-000000002103', MarketplaceType::WILDBERRIES);

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:ozon-accrual:rolling-refresh');
        $exit = $tester->execute([
            '--days-back' => '14',
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(1, $this->parentBackfillJobCount($ozonCompany->getId()));
        self::assertSame(0, $this->parentBackfillJobCount($wbCompany->getId()));

        $parent = $this->connection->fetchAssociative(
            'SELECT resource_type, shop_ref, progress_total
             FROM ingest_sync_jobs
             WHERE company_id = :companyId AND parent_job_id IS NULL',
            ['companyId' => $ozonCompany->getId()],
        );

        self::assertIsArray($parent);
        self::assertSame(OzonResourceType::ACCRUAL_BY_DAY, $parent['resource_type']);
        self::assertSame($ozonConnection->getId(), $parent['shop_ref']);
        self::assertSame(2, (int) $parent['progress_total']);

        $envelopes = $transport->getSent();
        self::assertCount(2, $envelopes);
        foreach ($envelopes as $envelope) {
            self::assertInstanceOf(RunSyncChunkMessage::class, $envelope->getMessage());
        }
    }

    public function testActiveBackfillIsSkipped(): void
    {
        $company = $this->seedCompany(2104);
        $connection = $this->seedConnection($company, '77777777-7777-7777-7777-000000002104');

        $activeJob = new SyncJob(
            companyId: $company->getId(),
            connectionRef: $connection->getId(),
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-07'),
            shopRef: $connection->getId(),
        );
        $this->em->persist($activeJob);
        $this->em->flush();

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:ozon-accrual:rolling-refresh');
        $exit = $tester->execute([
            '--company-id' => $company->getId(),
            '--days-back' => '14',
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('active', $tester->getDisplay());
        self::assertSame(1, $this->parentBackfillJobCount($company->getId()));
        self::assertCount(0, $transport->getSent());
    }

    private function seedCompany(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function seedConnection(
        Company $company,
        string $id,
        MarketplaceType $marketplace = MarketplaceType::OZON,
    ): MarketplaceConnection {
        $connection = new MarketplaceConnection(
            id: $id,
            company: $company,
            marketplace: $marketplace,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key');
        $connection->setClientId('test-client-id');
        $connection->setIsActive(true);

        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    private function backfillJobCount(string $companyId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_sync_jobs WHERE company_id = :companyId AND kind = :kind',
            ['companyId' => $companyId, 'kind' => SyncJobKind::BACKFILL->value],
        );
    }

    private function parentBackfillJobCount(string $companyId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM ingest_sync_jobs
             WHERE company_id = :companyId AND kind = :kind AND parent_job_id IS NULL',
            ['companyId' => $companyId, 'kind' => SyncJobKind::BACKFILL->value],
        );
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
