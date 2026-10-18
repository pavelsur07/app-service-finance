<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\MessageHandler;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\MessageHandler\NormalizeRawRecordHandler;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class NormalizeRawRecordHandlerAccrualTypesTest extends IntegrationTestCase
{
    public function testNormalizedTypesVersionFillsDictionaryAndReplayIsHarmless(): void
    {
        $raw = $this->store(OzonResourceType::ACCRUAL_TYPES, 'accrual-types', [
            ['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг'],
            ['id' => 127, 'name' => 'AnalyticsPlus', 'description' => ''],
        ]);

        $this->handle($raw);
        $this->handle($raw);

        self::assertSame(RawNormalizationStatus::DONE->value, $this->connection->fetchOne(
            'SELECT normalization_status FROM ingest_raw_records WHERE id = :id',
            ['id' => $raw->getId()],
        ));
        self::assertSame(
            [[1, 'Acquiring'], [127, 'AnalyticsPlus']],
            array_map(
                static fn (array $row): array => [(int) $row['type_id'], $row['name']],
                $this->connection->fetchAllAssociative('SELECT type_id, name FROM ingest_ozon_accrual_types ORDER BY type_id'),
            ),
        );
    }

    public function testOtherResourceDoesNotTouchDictionary(): void
    {
        $raw = $this->store(OzonResourceType::ACCRUAL_POSTINGS, 'accrual-postings:1', [
            ['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг'],
        ]);

        $this->handle($raw);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ingest_ozon_accrual_types'));
    }

    private function handle(IngestRawRecord $raw): void
    {
        /** @var NormalizeRawRecordHandler $handler */
        $handler = self::getContainer()->get(NormalizeRawRecordHandler::class);
        $handler(new NormalizeRawRecordMessage($raw->getId(), $raw->getCompanyId()));
        $this->em->clear();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function store(string $resourceType, string $externalId, array $rows): IngestRawRecord
    {
        $companyId = Uuid::uuid7()->toString();

        /** @var RawStorageFacade $storage */
        $storage = self::getContainer()->get(RawStorageFacade::class);

        return $storage->store(new RawBatch(
            companyId: $companyId,
            connectionRef: $companyId,
            shopRef: $companyId,
            source: IngestSource::OZON,
            resourceType: $resourceType,
            externalId: $externalId,
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable('2026-10-08 02:30:00'),
            rows: $rows,
        ))[0];
    }
}
