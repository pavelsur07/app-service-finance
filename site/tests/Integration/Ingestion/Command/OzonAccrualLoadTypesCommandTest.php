<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Enum\SyncJobStatus;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class OzonAccrualLoadTypesCommandTest extends IntegrationTestCase
{
    public function testDispatchesAccrualTypesIncrementalJob(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:ozon-accrual:load-types');
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--connection-ref' => $connectionRef,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Ozon accrual types load job dispatched.', $tester->getDisplay());

        $jobs = $this->connection->fetchAllAssociative(
            'SELECT resource_type, kind, status, shop_ref
             FROM ingest_sync_jobs
             WHERE company_id = :companyId
             ORDER BY created_at',
            ['companyId' => $companyId],
        );

        self::assertCount(1, $jobs);
        self::assertSame(OzonResourceType::ACCRUAL_TYPES, $jobs[0]['resource_type']);
        self::assertSame(SyncJobKind::INCREMENTAL->value, $jobs[0]['kind']);
        self::assertSame(SyncJobStatus::OPEN->value, $jobs[0]['status']);
        self::assertSame($connectionRef, $jobs[0]['shop_ref']);

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(RunSyncChunkMessage::class, $sent[0]->getMessage());
    }

    public function testExecuteInlineStoresAccrualTypesRawRecord(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();

        $this->getIngestFetchTransport()->reset();
        $normalizeTransport = $this->getNormalizeTransport();
        $normalizeTransport->reset();

        $tester = $this->tester('app:ingestion:ozon-accrual:load-types');
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--connection-ref' => $connectionRef,
            '--execute-inline' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Stored raw record', $tester->getDisplay());
        self::assertStringContainsString('29', $tester->getDisplay());
        self::assertStringContainsString('Логистика', $tester->getDisplay());
        self::assertCount(0, $this->getIngestFetchTransport()->getSent());

        $jobs = $this->connection->fetchAllAssociative(
            'SELECT resource_type, kind, status
             FROM ingest_sync_jobs
             WHERE company_id = :companyId',
            ['companyId' => $companyId],
        );
        self::assertCount(1, $jobs);
        self::assertSame(OzonResourceType::ACCRUAL_TYPES, $jobs[0]['resource_type']);
        self::assertSame(SyncJobKind::INCREMENTAL->value, $jobs[0]['kind']);
        self::assertSame(SyncJobStatus::COMPLETED->value, $jobs[0]['status']);

        $rawRows = $this->connection->fetchAllAssociative(
            'SELECT resource_type, external_id, normalization_status
             FROM ingest_raw_records
             WHERE company_id = :companyId AND source = :source',
            [
                'companyId' => $companyId,
                'source' => IngestSource::OZON->value,
            ],
        );
        self::assertCount(1, $rawRows);
        self::assertSame(OzonResourceType::ACCRUAL_TYPES, $rawRows[0]['resource_type']);
        self::assertSame('accrual-types', $rawRows[0]['external_id']);
        self::assertSame(RawNormalizationStatus::DONE->value, $rawRows[0]['normalization_status']);

        $normalizeMessages = $normalizeTransport->getSent();
        self::assertCount(1, $normalizeMessages);
        self::assertInstanceOf(NormalizeRawRecordMessage::class, $normalizeMessages[0]->getMessage());
    }

    public function testActiveTypesLoadIsSkipped(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();

        $tester = $this->tester('app:ingestion:ozon-accrual:load-types');
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--company-id' => $companyId,
            '--connection-ref' => $connectionRef,
        ]));

        $second = $this->tester('app:ingestion:ozon-accrual:load-types');
        $exit = $second->execute([
            '--company-id' => $companyId,
            '--connection-ref' => $connectionRef,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('already running', $second->getDisplay());
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

    private function getNormalizeTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.ingest_normalize');

        return $transport;
    }
}
