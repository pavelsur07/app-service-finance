<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonReportDownload;
use App\MarketplaceAds\Message\DownloadOzonAdReportMessage;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
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
 * End-to-end тесты {@see DownloadOzonAdReportHandler}: boot kernel + реальный
 * Postgres + in-memory Messenger transport + mocked OzonAdClient.
 *
 * Покрываются:
 *  - happy path: pending OK → AdRawDocument upsert, bronze metadata, pending
 *    финализирован OK, ProcessAdRawDocumentMessage в async_pipeline transport;
 *  - идемпотентность: повторный запуск на том же pendingReportId — no-op
 *    (finalized_at guard блокирует повторный download).
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

    public function testEndToEndDownloadUpsertsRawDocumentAndDispatchesProcessing(): void
    {
        $company = $this->seedCompany();
        $this->em->flush();

        $pending = $this->seedPendingReport($company->getId(), ozonUuid: 'uuid-happy');
        $this->em->flush();
        $pendingId = $pending->getId();

        $download = new OzonReportDownload(
            rawBytes: 'date;sku;spend;views;clicks',
            csvContent: 'date;sku;spend;views;clicks',
            csvParts: ['date;sku;spend;views;clicks'],
            wasZip: false,
            sizeBytes: 28,
            sha256: hash('sha256', 'date;sku;spend;views;clicks'),
            reportUuid: 'uuid-happy',
            filesInZip: 0,
        );

        $this->clientMock
            ->method('downloadAndConvertReport')
            ->with($company->getId(), 'uuid-happy', ['CAMP-1'])
            ->willReturn([
                'downloads' => [$download],
                'resultByDate' => [
                    '2026-04-01' => ['campaigns' => [[
                        'campaign_id' => 'CAMP-1',
                        'campaign_name' => 'Camp 1',
                        'rows' => [['sku' => 'SKU-1', 'spend' => '10.00', 'views' => 5, 'clicks' => 1]],
                    ]]],
                    '2026-04-02' => ['campaigns' => [[
                        'campaign_id' => 'CAMP-1',
                        'campaign_name' => 'Camp 1',
                        'rows' => [['sku' => 'SKU-1', 'spend' => '20.00', 'views' => 10, 'clicks' => 2]],
                    ]]],
                ],
            ]);

        $handler = self::getContainer()->get(DownloadOzonAdReportHandler::class);

        $handler(new DownloadOzonAdReportMessage(
            companyId: $company->getId(),
            pendingReportId: $pendingId,
        ));

        $this->em->clear();

        // 1) Pending-отчёт финализирован OK.
        $finalized = $this->pendingRepo->findByOzonUuid($company->getId(), 'uuid-happy');
        self::assertNotNull($finalized);
        self::assertSame(OzonAdPendingReportState::OK, $finalized->getState());
        self::assertNotNull($finalized->getFinalizedAt(), 'pending-отчёт обязан быть финализирован');

        // 2) За каждый день отчёта создан AdRawDocument + bronze metadata.
        $docs = $this->rawRepo->findByCompanyMarketplaceAndDateRange(
            $company->getId(),
            MarketplaceType::OZON->value,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-02'),
        );
        self::assertCount(2, $docs);
        foreach ($docs as $doc) {
            self::assertSame(MarketplaceType::OZON, $doc->getMarketplace());
            self::assertNotNull($doc->getStoragePath(), 'bronze storage_path обязан быть заполнен');
            self::assertNotNull($doc->getFileHash());
            self::assertNotNull($doc->getFileSizeBytes());
            self::assertStringContainsString($company->getId(), (string) $doc->getStoragePath());
            self::assertStringContainsString('uuid-happy', (string) $doc->getStoragePath());
        }

        // 3) ProcessAdRawDocumentMessage задиспатчен за каждый документ.
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async_pipeline');
        $envelopes = $transport->get();
        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), iterator_to_array($envelopes));
        self::assertCount(2, $messages);
        foreach ($messages as $message) {
            self::assertInstanceOf(ProcessAdRawDocumentMessage::class, $message);
            self::assertSame($company->getId(), $message->companyId);
        }

        $dispatchedDocIds = array_map(static fn (ProcessAdRawDocumentMessage $m) => $m->adRawDocumentId, $messages);
        $actualDocIds = array_map(static fn (AdRawDocument $d) => $d->getId(), $docs);
        sort($dispatchedDocIds);
        sort($actualDocIds);
        self::assertSame($actualDocIds, $dispatchedDocIds, 'dispatch должен покрывать ровно созданные документы');
    }

    public function testSecondInvocationIsNoopWhenAlreadyFinalized(): void
    {
        $company = $this->seedCompany();
        $this->em->flush();

        $pending = $this->seedPendingReport($company->getId(), ozonUuid: 'uuid-race');
        $this->em->flush();
        $pendingId = $pending->getId();

        $this->clientMock->expects(self::once())
            ->method('downloadAndConvertReport')
            ->willReturn([
                'downloads' => [new OzonReportDownload(
                    rawBytes: 'b',
                    csvContent: 'b',
                    csvParts: ['b'],
                    wasZip: false,
                    sizeBytes: 1,
                    sha256: hash('sha256', 'b'),
                    reportUuid: 'uuid-race',
                    filesInZip: 0,
                )],
                'resultByDate' => [
                    '2026-04-01' => ['campaigns' => [[
                        'campaign_id' => 'CAMP-1',
                        'campaign_name' => 'N',
                        'rows' => [['sku' => 'S', 'spend' => '1.00', 'views' => 1, 'clicks' => 1]],
                    ]]],
                ],
            ]);

        $handler = self::getContainer()->get(DownloadOzonAdReportHandler::class);

        $message = new DownloadOzonAdReportMessage(
            companyId: $company->getId(),
            pendingReportId: $pendingId,
        );

        // Первый запуск — полноценный happy path.
        $handler($message);

        // Перед вторым запуском очищаем UoW, чтобы второй вызов шёл с чистым
        // Doctrine identity map (реалистичный кейс Messenger retry после
        // рестарта worker'а).
        $this->em->clear();

        // Второй запуск: clientMock настроен с expects(self::once()) — если
        // handler попытается повторно вызвать download, тест упадёт. Проверка
        // идемпотентности через finalized_at guard.
        $handler($message);

        $this->em->clear();

        // Pending всё ещё финализирован OK.
        $finalized = $this->pendingRepo->findByOzonUuid($company->getId(), 'uuid-race');
        self::assertNotNull($finalized);
        self::assertSame(OzonAdPendingReportState::OK, $finalized->getState());

        // AdRawDocument остался ровно один (повторный upsert не создавал дубликат).
        $docs = $this->rawRepo->findByCompanyMarketplaceAndDateRange(
            $company->getId(),
            MarketplaceType::OZON->value,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-01'),
        );
        self::assertCount(1, $docs);
    }

    public function testForeignCompanyIdIsIgnored(): void
    {
        $company = $this->seedCompany();
        $this->em->flush();

        $pending = $this->seedPendingReport($company->getId(), ozonUuid: 'uuid-foreign');
        $this->em->flush();
        $pendingId = $pending->getId();

        $this->clientMock->expects(self::never())->method('downloadAndConvertReport');

        $handler = self::getContainer()->get(DownloadOzonAdReportHandler::class);

        $handler(new DownloadOzonAdReportMessage(
            companyId: '99999999-9999-9999-9999-999999999999',
            pendingReportId: $pendingId,
        ));

        $this->em->clear();

        // Pending-отчёт остался в исходном состоянии (OK, не финализирован).
        $unchanged = $this->pendingRepo->findByOzonUuid($company->getId(), 'uuid-foreign');
        self::assertNotNull($unchanged);
        self::assertNull($unchanged->getFinalizedAt(), 'IDOR-guard: чужая company не должна финализировать pending-отчёт');
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
