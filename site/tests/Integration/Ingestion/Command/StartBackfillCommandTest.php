<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class StartBackfillCommandTest extends IntegrationTestCase
{
    public function testDryRunDoesNotCreateJobsOrDispatchMessages(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:start-backfill');
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--connection-ref' => $connectionRef,
            '--source' => 'ozon',
            '--days-back' => '30',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('DRY-RUN ingestion backfill', $tester->getDisplay());
        self::assertStringContainsString(OzonResourceType::DAILY_REPORT, $tester->getDisplay());
        self::assertStringNotContainsString(OzonResourceType::REALIZATION, $tester->getDisplay());
        self::assertSame(0, $this->syncJobCount($companyId));
        self::assertCount(0, $transport->getSent());
    }

    public function testStartsBackfillForDefaultOzonResourceType(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:start-backfill');
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--connection-ref' => $connectionRef,
            '--source' => 'ozon',
            '--days-back' => '30',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString(OzonResourceType::DAILY_REPORT, $tester->getDisplay());
        self::assertStringNotContainsString(OzonResourceType::REALIZATION, $tester->getDisplay());

        $parentJobs = $this->connection->fetchAllAssociative(
            'SELECT resource_type, shop_ref, progress_total
             FROM ingest_sync_jobs
             WHERE company_id = :companyId AND parent_job_id IS NULL
             ORDER BY resource_type',
            ['companyId' => $companyId],
        );

        self::assertCount(1, $parentJobs);
        self::assertSame([OzonResourceType::DAILY_REPORT], array_column($parentJobs, 'resource_type'));
        self::assertSame($connectionRef, $parentJobs[0]['shop_ref']);
        self::assertSame(5, (int) $parentJobs[0]['progress_total']);

        $envelopes = $transport->getSent();
        self::assertCount(5, $envelopes);
        foreach ($envelopes as $envelope) {
            self::assertInstanceOf(RunSyncChunkMessage::class, $envelope->getMessage());
        }
    }

    public function testActiveBackfillWarningSkipsDefaultResource(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $activeJob = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::DAILY_REPORT,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-18'),
            shopRef: $connectionRef,
        );
        $this->em->persist($activeJob);
        $this->em->flush();

        $tester = $this->tester('app:ingestion:start-backfill');
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--connection-ref' => $connectionRef,
            '--source' => 'ozon',
            '--days-back' => '30',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Backfill already running', $tester->getDisplay());

        $newParentJobs = $this->connection->fetchAllAssociative(
            'SELECT resource_type
             FROM ingest_sync_jobs
             WHERE company_id = :companyId AND parent_job_id IS NULL
             ORDER BY resource_type',
            ['companyId' => $companyId],
        );

        self::assertSame([OzonResourceType::DAILY_REPORT], array_column($newParentJobs, 'resource_type'));
        self::assertCount(0, $transport->getSent());
    }

    public function testStartsBackfillForExplicitAccrualResourceType(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:start-backfill');
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--connection-ref' => $connectionRef,
            '--source' => 'ozon',
            '--days-back' => '30',
            '--resource-type' => OzonResourceType::ACCRUAL_POSTINGS,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString(OzonResourceType::ACCRUAL_POSTINGS, $tester->getDisplay());

        $parentJobs = $this->connection->fetchAllAssociative(
            'SELECT resource_type, progress_total
             FROM ingest_sync_jobs
             WHERE company_id = :companyId AND parent_job_id IS NULL
             ORDER BY resource_type',
            ['companyId' => $companyId],
        );

        self::assertCount(1, $parentJobs);
        self::assertSame([OzonResourceType::ACCRUAL_POSTINGS], array_column($parentJobs, 'resource_type'));
        self::assertSame(5, (int) $parentJobs[0]['progress_total']);
        self::assertCount(5, $transport->getSent());
    }

    public function testRealizationBackfillIsNotSupportedUntilMonthAlignedChunkingExists(): void
    {
        $tester = $this->tester('app:ingestion:start-backfill');
        $exit = $tester->execute([
            '--company-id' => Uuid::uuid7()->toString(),
            '--connection-ref' => Uuid::uuid7()->toString(),
            '--source' => 'ozon',
            '--resource-type' => OzonResourceType::REALIZATION,
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Unsupported resource type', $tester->getDisplay());
    }

    public function testStaticAccrualTypesBackfillIsNotSupported(): void
    {
        $tester = $this->tester('app:ingestion:start-backfill');
        $exit = $tester->execute([
            '--company-id' => Uuid::uuid7()->toString(),
            '--connection-ref' => Uuid::uuid7()->toString(),
            '--source' => 'ozon',
            '--resource-type' => OzonResourceType::ACCRUAL_TYPES,
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Unsupported resource type', $tester->getDisplay());
    }

    public function testInvalidCompanyUuidFails(): void
    {
        $tester = $this->tester('app:ingestion:start-backfill');
        $exit = $tester->execute([
            '--company-id' => 'not-a-uuid',
            '--connection-ref' => Uuid::uuid7()->toString(),
            '--source' => 'ozon',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Invalid --company-id UUID', $tester->getDisplay());
    }

    private function syncJobCount(string $companyId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_sync_jobs WHERE company_id = :companyId',
            ['companyId' => $companyId],
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
