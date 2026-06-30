<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayPreviewMapper;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Enum\SyncJobStatus;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\MessageHandler\NormalizeRawRecordHandler;
use App\Ingestion\MessageHandler\RunSyncChunkHandler;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Ingestion\Repository\NormalizationIssueRepository;
use App\Ingestion\Repository\SyncJobRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class OzonIngestionFlowTest extends IntegrationTestCase
{
    public function testRunSyncChunkThroughFakeOzonAccrualClientStoresRawAndNormalizesCanon(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: 'marketplace:ozon:seller',
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-18'),
            windowTo: new \DateTimeImmutable('2026-06-18'),
            shopRef: 'ozon-shop',
        );

        $this->em->persist($job);
        $this->em->flush();

        $normalizeTransport = $this->getNormalizeTransport();
        $normalizeTransport->reset();

        /** @var RunSyncChunkHandler $handler */
        $handler = self::getContainer()->get(RunSyncChunkHandler::class);
        $handler(new RunSyncChunkMessage($companyId, $job->getId()));
        $this->em->clear();

        /** @var SyncJobRepository $jobRepository */
        $jobRepository = self::getContainer()->get(SyncJobRepository::class);
        self::assertSame(SyncJobStatus::COMPLETED, $jobRepository->findByIdAndCompany($job->getId(), $companyId)?->getStatus());

        $envelopes = $normalizeTransport->getSent();
        self::assertCount(1, $envelopes);
        self::assertInstanceOf(NormalizeRawRecordMessage::class, $envelopes[0]->getMessage());

        /** @var NormalizeRawRecordMessage $normalizeMessage */
        $normalizeMessage = $envelopes[0]->getMessage();
        /** @var IngestRawRecordRepository $rawRecordRepository */
        $rawRecordRepository = self::getContainer()->get(IngestRawRecordRepository::class);
        $rawRecord = $rawRecordRepository->findByIdAndCompany($normalizeMessage->rawRecordId, $companyId);
        self::assertInstanceOf(IngestRawRecord::class, $rawRecord);
        self::assertSame(OzonResourceType::ACCRUAL_BY_DAY, $rawRecord->getResourceType());

        /** @var NormalizeRawRecordHandler $normalizeHandler */
        $normalizeHandler = self::getContainer()->get(NormalizeRawRecordHandler::class);
        $normalizeHandler($normalizeMessage);
        $this->em->clear();

        /** @var FinancialTransactionRepository $transactionRepository */
        $transactionRepository = self::getContainer()->get(FinancialTransactionRepository::class);
        $transactions = $transactionRepository->findByRawRecordId($companyId, $normalizeMessage->rawRecordId);

        self::assertCount(3, $transactions);
        $externalIds = array_map(static fn ($transaction): string => $transaction->getExternalId(), $transactions);
        sort($externalIds);
        self::assertSame([
            'ozon:accrual-by-day:53675409100:commission:product-0',
            'ozon:accrual-by-day:53675409100:delivery:product-0:service-0:type-29',
            'ozon:accrual-by-day:53675409100:sale:product-0',
        ], $externalIds);

        /** @var NormalizationIssueRepository $issueRepository */
        $issueRepository = self::getContainer()->get(NormalizationIssueRepository::class);
        self::assertSame([], $issueRepository->findOpenByRawRecord($companyId, $normalizeMessage->rawRecordId));
    }

    public function testRunSyncChunkDoesNotDispatchNormalizationForUnchangedDoneRaw(): void
    {
        $companyId = Uuid::uuid7()->toString();

        $firstJob = new SyncJob(
            companyId: $companyId,
            connectionRef: 'marketplace:ozon:seller',
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-18'),
            windowTo: new \DateTimeImmutable('2026-06-18'),
            shopRef: 'ozon-shop',
        );
        $this->em->persist($firstJob);
        $this->em->flush();

        $normalizeTransport = $this->getNormalizeTransport();
        $normalizeTransport->reset();

        /** @var RunSyncChunkHandler $syncHandler */
        $syncHandler = self::getContainer()->get(RunSyncChunkHandler::class);
        $syncHandler(new RunSyncChunkMessage($companyId, $firstJob->getId()));

        $envelopes = $normalizeTransport->getSent();
        self::assertCount(1, $envelopes);

        /** @var NormalizeRawRecordMessage $normalizeMessage */
        $normalizeMessage = $envelopes[0]->getMessage();
        /** @var NormalizeRawRecordHandler $normalizeHandler */
        $normalizeHandler = self::getContainer()->get(NormalizeRawRecordHandler::class);
        $normalizeHandler($normalizeMessage);
        $this->em->clear();

        $secondJob = new SyncJob(
            companyId: $companyId,
            connectionRef: 'marketplace:ozon:seller',
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-18'),
            windowTo: new \DateTimeImmutable('2026-06-18'),
            shopRef: 'ozon-shop',
        );
        $this->em->persist($secondJob);
        $this->em->flush();

        $normalizeTransport->reset();
        $syncHandler(new RunSyncChunkMessage($companyId, $secondJob->getId()));

        self::assertCount(0, $normalizeTransport->getSent());
    }

    public function testPreviewMapperUsesStoredAccrualTypesDictionary(): void
    {
        $companyId = Uuid::uuid7()->toString();

        /** @var RawStorageFacade $rawStorageFacade */
        $rawStorageFacade = self::getContainer()->get(RawStorageFacade::class);
        $rawStorageFacade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: 'marketplace:ozon:seller',
            shopRef: 'ozon-shop',
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_TYPES,
            externalId: 'accrual-types',
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable('2026-06-24 10:00:00'),
            rows: [['type_id' => 1, 'name' => 'Эквайринг']],
        ));
        $this->em->flush();

        /** @var OzonAccrualByDayPreviewMapper $mapper */
        $mapper = self::getContainer()->get(OzonAccrualByDayPreviewMapper::class);
        $rows = $mapper->preview($companyId, [[
            'accrual_id' => 53675409101,
            'date' => '2026-06-13',
            'accrued_category' => 'ITEM',
            'item_fees' => [
                'fees' => [[
                    'fees' => [
                        ['type_id' => 1, 'accrued' => ['amount' => '-18.66', 'currency' => 'RUB']],
                    ],
                ]],
            ],
        ]]);

        self::assertCount(1, $rows);
        self::assertSame('1', $rows[0]->typeId);
        self::assertSame('ozon_acquiring', $rows[0]->ozonCategoryCode);
        self::assertSame('Эквайринг', $rows[0]->ozonCategoryLabel);
        self::assertTrue($rows[0]->ozonCategoryKnown);
    }

    public function testPreviewMapperRefreshesStoredAccrualTypesDictionaryAfterLoad(): void
    {
        $companyId = Uuid::uuid7()->toString();

        /** @var OzonAccrualByDayPreviewMapper $mapper */
        $mapper = self::getContainer()->get(OzonAccrualByDayPreviewMapper::class);
        $rowsBeforeDictionary = $mapper->preview($companyId, [$this->itemFeeTypeOneRow()]);

        self::assertCount(1, $rowsBeforeDictionary);
        self::assertSame('1', $rowsBeforeDictionary[0]->typeId);
        self::assertFalse($rowsBeforeDictionary[0]->ozonCategoryKnown);

        /** @var RawStorageFacade $rawStorageFacade */
        $rawStorageFacade = self::getContainer()->get(RawStorageFacade::class);
        $rawStorageFacade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: 'marketplace:ozon:seller',
            shopRef: 'ozon-shop',
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_TYPES,
            externalId: 'accrual-types',
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable('2026-06-24 10:00:00'),
            rows: [['type_id' => 1, 'name' => 'Эквайринг']],
        ));
        $this->em->flush();

        $rowsAfterDictionary = $mapper->preview($companyId, [$this->itemFeeTypeOneRow()]);

        self::assertCount(1, $rowsAfterDictionary);
        self::assertSame('1', $rowsAfterDictionary[0]->typeId);
        self::assertSame('ozon_acquiring', $rowsAfterDictionary[0]->ozonCategoryCode);
        self::assertSame('Эквайринг', $rowsAfterDictionary[0]->ozonCategoryLabel);
        self::assertTrue($rowsAfterDictionary[0]->ozonCategoryKnown);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemFeeTypeOneRow(): array
    {
        return [
            'accrual_id' => 53675409101,
            'date' => '2026-06-13',
            'accrued_category' => 'ITEM',
            'item_fees' => [
                'fees' => [[
                    'fees' => [
                        ['type_id' => 1, 'accrued' => ['amount' => '-18.66', 'currency' => 'RUB']],
                    ],
                ]],
            ],
        ];
    }

    private function getNormalizeTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.ingest_normalize');

        return $transport;
    }
}
