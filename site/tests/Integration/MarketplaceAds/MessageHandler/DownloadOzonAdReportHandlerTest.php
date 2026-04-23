<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Message\DownloadOzonAdReportMessage;
use App\MarketplaceAds\MessageHandler\DownloadOzonAdReportHandler;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Transport\InMemoryTransport;

/**
 * End-to-end тесты {@see DownloadOzonAdReportHandler} (task-8 flow): boot kernel
 * + реальный Postgres + in-memory Messenger transport + mocked OzonAdClient.
 *
 * Покрываются:
 *  - happy path: pending OK → raw-файл сохранён на диск, AdRawDocument upsert
 *    с storage_path/hash/size, rawPayload='{}', status=DRAFT, pending
 *    финализирован OK; ProcessAdRawDocumentMessage в async_pipeline
 *    transport НЕ появляется (парсинг отключён);
 *  - идемпотентность: повторный запуск — no-op (finalized_at guard).
 */
final class DownloadOzonAdReportHandlerTest extends IntegrationTestCase
{
    private OzonAdPendingReportRepository $pendingRepo;
    private AdRawDocumentRepository $rawRepo;
    /** @var OzonAdClient&MockObject */
    private OzonAdClient $clientMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pendingRepo = self::getContainer()->get(OzonAdPendingReportRepository::class);
        $this->rawRepo = self::getContainer()->get(AdRawDocumentRepository::class);

        $this->clientMock = $this->createMock(OzonAdClient::class);
        self::getContainer()->set(OzonAdClient::class, $this->clientMock);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async_pipeline');
        $transport->reset();
    }

    public function testEndToEndSavesFileAndUpsertsRawDocumentsWithoutDispatch(): void
    {
        $company = $this->seedCompany();
        $this->em->flush();

        $ozonUuid = Uuid::uuid7()->toString();
        $pending = $this->seedPendingReport($company->getId(), ozonUuid: $ozonUuid);
        $this->em->flush();
        $pendingId = $pending->getId();

        $csvBody = "date;sku;spend\n2026-04-01;S1;1.00\n2026-04-02;S1;2.00";

        $this->clientMock
            ->method('fetchReportContent')
            ->with($company->getId(), $ozonUuid)
            ->willReturn([
                'body' => $csvBody,
                'contentType' => 'text/csv',
            ]);

        $handler = self::getContainer()->get(DownloadOzonAdReportHandler::class);

        $handler(new DownloadOzonAdReportMessage(
            companyId: $company->getId(),
            pendingReportId: $pendingId,
        ));

        $this->em->clear();

        // 1) Pending-отчёт финализирован OK.
        $finalized = $this->pendingRepo->findByOzonUuid($company->getId(), $ozonUuid);
        self::assertNotNull($finalized);
        self::assertSame(OzonAdPendingReportState::OK, $finalized->getState());
        self::assertNotNull($finalized->getFinalizedAt(), 'pending-отчёт обязан быть финализирован');

        // 2) За каждый день pending-диапазона создан AdRawDocument + bronze metadata.
        $docs = $this->rawRepo->findByCompanyMarketplaceAndDateRange(
            $company->getId(),
            MarketplaceType::OZON->value,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-02'),
        );
        self::assertCount(2, $docs);
        foreach ($docs as $doc) {
            self::assertSame(MarketplaceType::OZON, $doc->getMarketplace());
            self::assertSame(AdRawDocumentStatus::DRAFT, $doc->getStatus());
            self::assertSame('{}', $doc->getRawPayload(), 'raw_payload = "{}" — контент только на диске');
            self::assertNotNull($doc->getStoragePath());
            self::assertNotNull($doc->getFileHash());
            self::assertNotNull($doc->getFileSizeBytes());
            self::assertStringContainsString($company->getId(), (string) $doc->getStoragePath());
            self::assertStringContainsString($ozonUuid, (string) $doc->getStoragePath());
            self::assertStringEndsWith('.csv', (string) $doc->getStoragePath());
        }

        // 3) Парсинг отключён — ProcessAdRawDocumentMessage НЕ диспатчится.
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async_pipeline');
        $envelopes = $transport->get();
        self::assertCount(0, iterator_to_array($envelopes), 'ProcessAdRawDocumentMessage не должен диспатчиться (task-8)');
    }

    public function testSecondInvocationIsNoopWhenAlreadyFinalized(): void
    {
        $company = $this->seedCompany();
        $this->em->flush();

        $ozonUuid = Uuid::uuid7()->toString();
        $pending = $this->seedPendingReport($company->getId(), ozonUuid: $ozonUuid);
        $this->em->flush();
        $pendingId = $pending->getId();

        $this->clientMock->expects(self::once())
            ->method('fetchReportContent')
            ->willReturn([
                'body' => 'csv-bytes',
                'contentType' => 'text/csv',
            ]);

        $handler = self::getContainer()->get(DownloadOzonAdReportHandler::class);

        $message = new DownloadOzonAdReportMessage(
            companyId: $company->getId(),
            pendingReportId: $pendingId,
        );

        $handler($message);
        $this->em->clear();

        // Второй запуск — clientMock setup с expects(once) упадёт, если handler
        // попробует снова вызвать fetchReportContent. finalized_at guard.
        $handler($message);
        $this->em->clear();

        $finalized = $this->pendingRepo->findByOzonUuid($company->getId(), $ozonUuid);
        self::assertNotNull($finalized);
        self::assertSame(OzonAdPendingReportState::OK, $finalized->getState());

        $docs = $this->rawRepo->findByCompanyMarketplaceAndDateRange(
            $company->getId(),
            MarketplaceType::OZON->value,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-02'),
        );
        // 2 дня в диапазоне → 2 документа. Повторный запуск не создаёт дубликаты.
        self::assertCount(2, $docs);
        foreach ($docs as $doc) {
            self::assertInstanceOf(AdRawDocument::class, $doc);
        }
    }

    public function testForeignCompanyIdIsIgnored(): void
    {
        $company = $this->seedCompany();
        $this->em->flush();

        $ozonUuid = Uuid::uuid7()->toString();
        $pending = $this->seedPendingReport($company->getId(), ozonUuid: $ozonUuid);
        $this->em->flush();
        $pendingId = $pending->getId();

        $this->clientMock->expects(self::never())->method('fetchReportContent');

        $handler = self::getContainer()->get(DownloadOzonAdReportHandler::class);

        $handler(new DownloadOzonAdReportMessage(
            companyId: '99999999-9999-9999-9999-999999999999',
            pendingReportId: $pendingId,
        ));

        $this->em->clear();

        // IDOR-guard: чужая company не должна финализировать pending-отчёт.
        $unchanged = $this->pendingRepo->findByOzonUuid($company->getId(), $ozonUuid);
        self::assertNotNull($unchanged);
        self::assertNull($unchanged->getFinalizedAt());
    }

    private function seedCompany(): Company
    {
        $companyId = Uuid::uuid7()->toString();
        $ownerId = Uuid::uuid7()->toString();

        $owner = UserBuilder::aUser()
            ->withId($ownerId)
            ->withEmail(sprintf('download-handler+%s@example.test', substr($ownerId, -12)))
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function seedPendingReport(
        string $companyId,
        string $ozonUuid,
    ): OzonAdPendingReport {
        $report = new OzonAdPendingReport(
            companyId: $companyId,
            ozonUuid: $ozonUuid,
            dateFrom: new \DateTimeImmutable('2026-04-01'),
            dateTo: new \DateTimeImmutable('2026-04-02'),
            campaignIds: ['CAMP-1'],
            jobId: null,
        );

        $ref = new \ReflectionClass($report);
        $stateProp = $ref->getProperty('state');
        $stateProp->setAccessible(true);
        $stateProp->setValue($report, OzonAdPendingReportState::OK);

        $this->em->persist($report);

        return $report;
    }
}
