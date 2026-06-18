<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Action\NormalizeRawRecordAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\Source\Ozon\OzonOperationKey;
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
    public function testRunSyncChunkThroughFakeOzonAdapterStoresRawAndNormalizesCanon(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: 'marketplace:ozon:seller',
            source: IngestSource::OZON,
            resourceType: OzonResourceType::DAILY_REPORT,
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
        self::assertNotNull($rawRecord);
        self::assertSame(OzonResourceType::DAILY_REPORT, $rawRecord->getResourceType());

        /** @var NormalizeRawRecordHandler $normalizeHandler */
        $normalizeHandler = self::getContainer()->get(NormalizeRawRecordHandler::class);
        $normalizeHandler($normalizeMessage);
        $this->em->clear();

        /** @var FinancialTransactionRepository $transactionRepository */
        $transactionRepository = self::getContainer()->get(FinancialTransactionRepository::class);
        $transactions = $transactionRepository->findByRawRecordId($companyId, $normalizeMessage->rawRecordId);

        self::assertCount(4, $transactions);
        $externalIds = array_map(static fn ($transaction): string => $transaction->getExternalId(), $transactions);
        sort($externalIds);
        $expectedExternalIds = [
            'ozon:operation:fake-ozon-op-1:sale',
            'ozon:operation:fake-ozon-op-1:commission',
            'ozon:operation:fake-ozon-op-1:logistics_delivery',
            'ozon:operation:fake-ozon-op-1:service_marketplaceserviceitemdelivtocustomer',
        ];
        sort($expectedExternalIds);
        self::assertSame(
            $expectedExternalIds,
            $externalIds,
        );
    }

    public function testRealizationUpdatesDailyTransactionsBySameNaturalKeys(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $dailyRows = $this->fixtureRows('transaction_list_with_sale_and_commission.json');
        $realizationRows = $this->fixtureRows('realization_february_2026.json');
        $dailyRecord = $this->storeRawRecord($companyId, OzonResourceType::DAILY_REPORT, 'daily-2026-02', $dailyRows);
        $realizationRecord = $this->storeRawRecord($companyId, OzonResourceType::REALIZATION, 'realization-2026-02', $realizationRows);

        $this->normalize($dailyRecord->getId(), $companyId);
        $this->normalize($realizationRecord->getId(), $companyId);
        $this->em->clear();

        $operationGroupId = (new OzonOperationKey())->operationGroupId($companyId, $dailyRows[0]);
        /** @var FinancialTransactionRepository $transactionRepository */
        $transactionRepository = self::getContainer()->get(FinancialTransactionRepository::class);
        $transactions = $transactionRepository->findByOperationGroup($companyId, $operationGroupId);

        self::assertCount(6, $transactions);
        $byExternalId = [];
        foreach ($transactions as $transaction) {
            $byExternalId[$transaction->getExternalId()] = $transaction;
        }

        self::assertSame(121000, $byExternalId['ozon:operation:1234567890:sale']->getAmountMinor());
        self::assertSame(
            '2026-03-05 00:00:00',
            $byExternalId['ozon:operation:1234567890:sale']->getExternalUpdatedAt()->format('Y-m-d H:i:s'),
        );

        /** @var NormalizationIssueRepository $issueRepository */
        $issueRepository = self::getContainer()->get(NormalizationIssueRepository::class);
        self::assertSame([], $issueRepository->findOpenByRawRecord($companyId, $dailyRecord->getId()));
        self::assertSame([], $issueRepository->findOpenByRawRecord($companyId, $realizationRecord->getId()));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeRawRecord(string $companyId, string $resourceType, string $externalId, array $rows): IngestRawRecord
    {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        return $facade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: 'marketplace:ozon:seller',
            shopRef: 'ozon-shop',
            source: IngestSource::OZON,
            resourceType: $resourceType,
            externalId: $externalId,
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable('2026-03-05T00:00:00+00:00'),
            rows: $rows,
        ))[0];
    }

    private function normalize(string $rawRecordId, string $companyId): void
    {
        /** @var NormalizeRawRecordAction $action */
        $action = self::getContainer()->get(NormalizeRawRecordAction::class);
        $action(new NormalizeRawRecordCommand($rawRecordId, $companyId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureRows(string $fileName): array
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__.'/../../../../../Fixtures/Ingestion/Ozon/'.$fileName),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($payload);
        self::assertIsArray($payload['rows'] ?? null);

        /** @var list<array<string, mixed>> $rows */
        $rows = $payload['rows'];

        return $rows;
    }

    private function getNormalizeTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.ingest_normalize');

        return $transport;
    }
}
