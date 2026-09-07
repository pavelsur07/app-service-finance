<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Action\NormalizeRawRecordAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayPreviewMapper;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Enum\SyncJobStatus;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\MessageHandler\NormalizeRawRecordHandler;
use App\Ingestion\MessageHandler\RunSyncChunkHandler;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Ingestion\Repository\NormalizationIssueRepository;
use App\Ingestion\Repository\SyncJobRepository;
use App\Shared\Domain\ValueObject\Money;
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

    public function testForceReplayVoidsSameRawComponentWhenTaxonomyChangesItsType(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $fetchedAt = new \DateTimeImmutable('2026-09-01 10:00:00');
        $row = [
            'accrual_id' => 53675409201,
            'date' => '2026-08-01',
            'accrued_category' => 'NON_ITEM',
            'non_item_fee' => [
                'type_id' => 1042,
                'name' => 'LabelBrandVerified',
                'accrued' => ['amount' => '-1500.00', 'currency' => 'RUB'],
            ],
        ];

        /** @var RawStorageFacade $rawStorageFacade */
        $rawStorageFacade = self::getContainer()->get(RawStorageFacade::class);
        [$rawRecord] = $rawStorageFacade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: 'marketplace:ozon:seller',
            shopRef: 'ozon-shop',
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            externalId: 'accrual-by-day:2026-08-01:2026-08-01',
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: $fetchedAt,
            rows: [$row],
        ));

        $externalId = 'ozon:accrual-by-day:53675409201:non_item_fee:type-1042';
        $legacyTransaction = new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $rawRecord->getConnectionRef(),
            shopRef: $rawRecord->getShopRef(),
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: $fetchedAt,
            operationGroupId: Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:accrual-by-day:%s', $companyId, '53675409201'))->toString(),
            type: TransactionType::OTHER,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor(150000, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-08-01 00:00:00 Europe/Moscow'),
            rawRecordId: $rawRecord->getId(),
            description: 'Ozon: Маркировка проверенного бренда',
            sourceData: ['_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY],
            sourceTz: 'Europe/Moscow',
        );
        $rawRecord->markNormalizationDone();
        $this->em->persist($legacyTransaction);
        $this->em->flush();

        /** @var NormalizeRawRecordAction $normalize */
        $normalize = self::getContainer()->get(NormalizeRawRecordAction::class);
        $command = new NormalizeRawRecordCommand($rawRecord->getId(), $companyId, forceReplay: true);
        $normalize($command);
        $normalize($command);
        $this->em->clear();

        /** @var FinancialTransactionRepository $transactionRepository */
        $transactionRepository = self::getContainer()->get(FinancialTransactionRepository::class);
        $transactions = $transactionRepository->findByRawRecordId($companyId, $rawRecord->getId());
        self::assertCount(2, $transactions);

        $transactionsByType = [];
        foreach ($transactions as $transaction) {
            $transactionsByType[$transaction->getType()->value] = $transaction;
        }

        self::assertArrayHasKey(TransactionType::OTHER->value, $transactionsByType);
        self::assertArrayHasKey(TransactionType::FEE->value, $transactionsByType);
        $legacy = $transactionsByType[TransactionType::OTHER->value];
        $normalized = $transactionsByType[TransactionType::FEE->value];

        self::assertSame(0, $legacy->getAmountMinor());
        self::assertTrue($legacy->getSourceData()['_ingestion_voided']);
        self::assertSame('ozon_mapper_component_retyped', $legacy->getSourceData()['_ingestion_void_reason']);
        self::assertSame(150000, $normalized->getAmountMinor());
        self::assertSame(TransactionDirection::OUT, $normalized->getDirection());
    }

    public function testRegularRenormalizationVoidsSameRawComponentWhenTaxonomyChangesItsType(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $fetchedAt = new \DateTimeImmutable('2026-09-01 10:00:00');
        $row = [
            'accrual_id' => 53675409202,
            'date' => '2026-08-01',
            'accrued_category' => 'NON_ITEM',
            'non_item_fee' => [
                'type_id' => 1042,
                'name' => 'LabelBrandVerified',
                'accrued' => ['amount' => '-1500.00', 'currency' => 'RUB'],
            ],
        ];

        /** @var RawStorageFacade $rawStorageFacade */
        $rawStorageFacade = self::getContainer()->get(RawStorageFacade::class);
        [$rawRecord] = $rawStorageFacade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: 'marketplace:ozon:seller',
            shopRef: 'ozon-shop',
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            externalId: 'accrual-by-day:2026-08-01:2026-08-01',
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: $fetchedAt,
            rows: [$row],
        ));

        $externalId = 'ozon:accrual-by-day:53675409202:non_item_fee:type-1042';
        $legacyTransaction = new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $rawRecord->getConnectionRef(),
            shopRef: $rawRecord->getShopRef(),
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: $fetchedAt,
            operationGroupId: Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:accrual-by-day:%s', $companyId, '53675409202'))->toString(),
            type: TransactionType::OTHER,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor(150000, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-08-01 00:00:00 Europe/Moscow'),
            rawRecordId: $rawRecord->getId(),
            description: 'Ozon: Маркировка проверенного бренда',
            sourceData: ['_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY],
            sourceTz: 'Europe/Moscow',
        );
        $rawRecord->markNormalizationDone();
        $rawRecord->markNormalizationFailed();
        $rawRecord->markNormalizationPending();
        $this->em->persist($legacyTransaction);
        $this->em->flush();

        /** @var NormalizeRawRecordAction $normalize */
        $normalize = self::getContainer()->get(NormalizeRawRecordAction::class);
        $normalize(new NormalizeRawRecordCommand($rawRecord->getId(), $companyId));
        $this->em->clear();

        /** @var FinancialTransactionRepository $transactionRepository */
        $transactionRepository = self::getContainer()->get(FinancialTransactionRepository::class);
        $transactions = $transactionRepository->findByRawRecordId($companyId, $rawRecord->getId());
        self::assertCount(2, $transactions);

        $amountByType = [];
        foreach ($transactions as $transaction) {
            $amountByType[$transaction->getType()->value] = $transaction->getAmountMinor();
        }

        self::assertArrayHasKey(TransactionType::OTHER->value, $amountByType);
        self::assertArrayHasKey(TransactionType::FEE->value, $amountByType);
        self::assertSame(0, $amountByType[TransactionType::OTHER->value]);
        self::assertSame(150000, $amountByType[TransactionType::FEE->value]);
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
