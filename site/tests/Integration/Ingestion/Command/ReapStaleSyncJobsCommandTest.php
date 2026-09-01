<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Enum\SyncJobStatus;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReapStaleSyncJobsCommandTest extends IntegrationTestCase
{
    /**
     * Задача, зависшая в RUNNING, блокирует ресурс НАВСЕГДА:
     * SyncJobRepository::findLatestForResource() считает активной любую задачу
     * в OPEN/RUNNING без ограничения по возрасту, а StartIncrementalAction
     * бросает на неё ActiveBackfillExistsException. Убитый воркер (SIGKILL,
     * OOM) не выполняет finally, и загрузка ресурса молча прекращается.
     */
    public function testStaleRunningJobIsFailedSoTheResourceUnblocks(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $job = $this->seedJob($companyId, SyncJobStatus::RUNNING, new \DateTimeImmutable('-10 hours'));

        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--older-than-hours' => '6']));

        self::assertSame('failed', $this->statusOf($job));
    }

    public function testFreshRunningJobIsLeftAlone(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $job = $this->seedJob($companyId, SyncJobStatus::RUNNING, new \DateTimeImmutable('-1 hour'));

        $this->makeTester()->execute(['--older-than-hours' => '6']);

        self::assertSame('running', $this->statusOf($job));
    }

    public function testDryRunChangesNothing(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $job = $this->seedJob($companyId, SyncJobStatus::OPEN, new \DateTimeImmutable('-10 hours'));

        $tester = $this->makeTester();
        $tester->execute(['--older-than-hours' => '6', '--dry-run' => true]);

        self::assertSame('open', $this->statusOf($job));
        self::assertStringContainsString('1', $tester->getDisplay());
    }

    /**
     * Уборка не должна трогать уже завершённые задачи: их статус — история.
     */
    public function testTerminalJobIsNotTouched(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $job = $this->seedJob($companyId, SyncJobStatus::RUNNING, new \DateTimeImmutable('-10 hours'));
        $this->connection->executeStatement(
            "UPDATE ingest_sync_jobs SET status = 'completed' WHERE id = :id",
            ['id' => $job],
        );

        $this->makeTester()->execute(['--older-than-hours' => '6']);

        self::assertSame('completed', $this->statusOf($job));
    }

    private function statusOf(string $jobId): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT status FROM ingest_sync_jobs WHERE id = :id',
            ['id' => $jobId],
        );
    }

    private function seedJob(string $companyId, SyncJobStatus $status, \DateTimeImmutable $updatedAt): string
    {
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: Uuid::uuid7()->toString(),
            source: IngestSource::OZON,
            resourceType: 'ozon_orders_fbo',
            kind: SyncJobKind::INCREMENTAL,
            shopRef: 'shop-main',
        );

        if (SyncJobStatus::RUNNING === $status) {
            $job->markRunning();
        }

        $this->em->persist($job);
        $this->em->flush();

        // Возраст задачи подделываем через SQL: сущность не даёт сдвинуть updatedAt.
        $this->connection->executeStatement(
            'UPDATE ingest_sync_jobs SET updated_at = :ts WHERE id = :id',
            ['ts' => $updatedAt->format('Y-m-d H:i:s'), 'id' => $job->getId()],
        );

        return $job->getId();
    }

    private function makeTester(): CommandTester
    {
        $app = new Application(self::bootKernel());

        return new CommandTester($app->find('app:ingestion:reap-stale-jobs'));
    }
}
