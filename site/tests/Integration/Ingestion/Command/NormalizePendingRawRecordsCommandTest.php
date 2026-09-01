<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class NormalizePendingRawRecordsCommandTest extends IntegrationTestCase
{
    /**
     * Запись ресурса без зарегистрированного маппера нормализовать нечем
     * НИКОГДА. Оставлять её PENDING — недостижимое состояние: выборка берёт
     * 50 самых старых pending по fetched_at, поэтому такие записи навсегда
     * занимают окно safety net и вытесняют из него финансовые raw-записи,
     * которые обработать можно.
     */
    public function testRecordWithoutMapperIsMarkedSkippedInsteadOfStayingPendingForever(): void
    {
        $companyId = Uuid::uuid7()->toString();

        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);
        $ids = $facade->storeAndGetIds(new RawBatch(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'connection-1',
            source: IngestSource::OZON,
            resourceType: 'ozon_seller_product_list',
            externalId: 'page-1',
            syncJobId: 'run-1',
            fetchedAt: new \DateTimeImmutable('-2 hours'),
            rows: [['product_id' => 1]],
        ));

        self::assertSame('pending', $this->statusOf($ids[0]));

        $tester = new CommandTester(
            (new Application(self::bootKernel()))->find('app:ingestion:normalize-pending'),
        );

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame('skipped', $this->statusOf($ids[0]));
    }

    private function statusOf(string $rawRecordId): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT normalization_status FROM ingest_raw_records WHERE id = :id',
            ['id' => $rawRecordId],
        );
    }
}
