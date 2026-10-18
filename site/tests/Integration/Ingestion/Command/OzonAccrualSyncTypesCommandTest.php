<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Facade\RawStorageFacade;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonAccrualSyncTypesCommandTest extends IntegrationTestCase
{
    public function testDryRunWritesNothingAndExecuteAppliesLatestVersion(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();
        $this->storeDoneVersion($companyA, [['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг']], '2026-10-01 02:30:00');
        $this->storeDoneVersion($companyB, [
            ['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг'],
            ['id' => 127, 'name' => 'AnalyticsPlus', 'description' => ''],
        ], '2026-10-08 02:30:00');

        $dryRun = $this->tester();
        self::assertSame(Command::SUCCESS, $dryRun->execute(['--dry-run' => true]));
        self::assertStringContainsString('Dry-run only', $dryRun->getDisplay());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ingest_ozon_accrual_types'));

        $execute = $this->tester();
        self::assertSame(Command::SUCCESS, $execute->execute([]));
        self::assertStringContainsString('dictionary synced', $execute->getDisplay());
        self::assertSame(
            [[1, 'Acquiring'], [127, 'AnalyticsPlus']],
            array_map(
                static fn (array $row): array => [(int) $row['type_id'], $row['name']],
                $this->connection->fetchAllAssociative('SELECT type_id, name FROM ingest_ozon_accrual_types ORDER BY type_id'),
            ),
        );
    }

    public function testPendingVersionIsIgnored(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $this->storeVersion($companyId, [['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг']], '2026-10-01 02:30:00');

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--company-id' => $companyId]));
        self::assertStringContainsString('No normalized Ozon accrual types versions found.', $tester->getDisplay());
    }

    public function testInvalidCompanyIdFails(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--company-id' => 'not-a-uuid']));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeDoneVersion(string $companyId, array $rows, string $fetchedAt): void
    {
        $raw = $this->storeVersion($companyId, $rows, $fetchedAt);
        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET normalization_status = :done WHERE id = :id',
            ['done' => RawNormalizationStatus::DONE->value, 'id' => $raw->getId()],
        );
        $this->em->clear();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeVersion(string $companyId, array $rows, string $fetchedAt): IngestRawRecord
    {
        /** @var RawStorageFacade $storage */
        $storage = self::getContainer()->get(RawStorageFacade::class);

        return $storage->store(new RawBatch(
            companyId: $companyId,
            connectionRef: $companyId,
            shopRef: $companyId,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_TYPES,
            externalId: 'accrual-types',
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable($fetchedAt),
            rows: $rows,
        ))[0];
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('app:ingestion:ozon-accrual:sync-types'));
    }
}
