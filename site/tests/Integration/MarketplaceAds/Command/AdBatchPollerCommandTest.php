<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Command;

use App\Company\Entity\Company;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Entity\AdScheduledBatch;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Shared\Service\Storage\StorageService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Builders\MarketplaceAds\AdScheduledBatchBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemoryTransport;

/**
 * End-to-end тесты {@see \App\MarketplaceAds\Command\AdBatchPollerCommand}:
 * реальный Postgres + mocked {@see OzonAdClient} + mocked {@see StorageService}.
 *
 * Central scenario (Task-11.6 acceptance): 3 IN_FLIGHT батча → 2 OK + 1 ERROR
 * → после прогона: 2 batch'а OK с заполненным storage, 1 FAILED с last_error.
 */
final class AdBatchPollerCommandTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-000000000001';

    private AdScheduledBatchRepository $batchRepo;
    private AdLoadJobRepository $jobRepo;
    /** @var OzonAdClient&MockObject */
    private OzonAdClient $clientMock;
    /** @var StorageService&MockObject */
    private StorageService $storageMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batchRepo = self::getContainer()->get(AdScheduledBatchRepository::class);
        $this->jobRepo = self::getContainer()->get(AdLoadJobRepository::class);

        $this->clientMock = $this->createMock(OzonAdClient::class);
        self::getContainer()->set(OzonAdClient::class, $this->clientMock);

        // Mock'аем storage — не хотим filesystem side-эффектов в тестах;
        // это unit-level уверенность в том, что команда вызывает storage
        // ровно с тем relativePath'ом, который ожидается.
        $this->storageMock = $this->createMock(StorageService::class);
        self::getContainer()->set(StorageService::class, $this->storageMock);
    }

    public function testEmptyDbExitsSuccessWithMessage(): void
    {
        $this->clientMock->expects(self::never())->method('pollOneReport');

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No IN_FLIGHT batches', $tester->getDisplay());
    }

    public function testMixedQueueTwoOkOneError(): void
    {
        $job = $this->seedJob();

        $batchOk1 = $this->persistBatch($job, batchIndex: 0, ozonUuid: 'aaaa1111-aaaa-aaaa-aaaa-aaaaaaaaaaa1');
        $batchOk2 = $this->persistBatch($job, batchIndex: 1, ozonUuid: 'aaaa2222-aaaa-aaaa-aaaa-aaaaaaaaaaa2');
        $batchErr = $this->persistBatch($job, batchIndex: 2, ozonUuid: 'aaaa3333-aaaa-aaaa-aaaa-aaaaaaaaaaa3');

        $this->clientMock->method('pollOneReport')
            ->willReturnMap([
                [self::COMPANY_ID, 'aaaa1111-aaaa-aaaa-aaaa-aaaaaaaaaaa1', ['state' => 'OK', 'raw' => []]],
                [self::COMPANY_ID, 'aaaa2222-aaaa-aaaa-aaaa-aaaaaaaaaaa2', ['state' => 'OK', 'raw' => []]],
                [self::COMPANY_ID, 'aaaa3333-aaaa-aaaa-aaaa-aaaaaaaaaaa3', ['state' => 'ERROR', 'raw' => []]],
            ]);

        $this->clientMock->method('fetchReportContent')
            ->willReturn(['body' => 'campaign_id,spend\nc1,100', 'contentType' => 'text/csv']);

        // storeBytes invoked ровно 2 раза (только для OK-батчей).
        $this->storageMock->expects(self::exactly(2))
            ->method('storeBytes')
            ->willReturnCallback(static fn (string $body, string $path): array => [
                'storagePath' => $path,
                'fileHash' => hash('sha256', $body),
                'sizeBytes' => strlen($body),
                'mimeType' => null,
            ]);

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('ok=2', $tester->getDisplay());
        self::assertStringContainsString('failed=1', $tester->getDisplay());

        $this->em->clear();

        $ok1 = $this->batchRepo->find($batchOk1->getId());
        self::assertNotNull($ok1);
        self::assertSame(AdScheduledBatchState::OK, $ok1->getState());
        self::assertNotNull($ok1->getStoragePath());
        self::assertNotNull($ok1->getFileHash());
        self::assertNotNull($ok1->getFileSize());
        self::assertGreaterThan(0, (int) $ok1->getFileSize());
        self::assertNotNull($ok1->getFinishedAt());
        self::assertStringContainsString('aaaa1111-aaaa-aaaa-aaaa-aaaaaaaaaaa1.csv', (string) $ok1->getStoragePath());

        $ok2 = $this->batchRepo->find($batchOk2->getId());
        self::assertNotNull($ok2);
        self::assertSame(AdScheduledBatchState::OK, $ok2->getState());

        $err = $this->batchRepo->find($batchErr->getId());
        self::assertNotNull($err);
        self::assertSame(AdScheduledBatchState::FAILED, $err->getState());
        self::assertStringContainsString('state=ERROR', (string) $err->getLastError());
        self::assertNotNull($err->getFinishedAt());
        self::assertNull($err->getStoragePath(), 'ERROR → без файла');
    }

    public function testZipContentTypeSavesWithZipExtension(): void
    {
        $job = $this->seedJob();
        $batch = $this->persistBatch($job, batchIndex: 0, ozonUuid: 'aaaazzz1-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

        $zipBytes = "PK\x03\x04".str_repeat("\x00", 100);

        $this->clientMock->method('pollOneReport')
            ->willReturn(['state' => 'OK', 'raw' => []]);
        $this->clientMock->method('fetchReportContent')
            ->willReturn(['body' => $zipBytes, 'contentType' => 'text/csv']); // Ozon иногда врёт в Content-Type

        $this->storageMock->expects(self::once())
            ->method('storeBytes')
            ->with(
                $zipBytes,
                self::stringEndsWith('.zip'),
            )
            ->willReturn([
                'storagePath' => sprintf('marketplace-ads/%s/%s.zip', self::COMPANY_ID, (string) $batch->getOzonUuid()),
                'fileHash' => 'zipsha',
                'sizeBytes' => strlen($zipBytes),
                'mimeType' => null,
            ]);

        $tester = $this->makeCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em->clear();
        $reloaded = $this->batchRepo->find($batch->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdScheduledBatchState::OK, $reloaded->getState());
        self::assertStringEndsWith('.zip', (string) $reloaded->getStoragePath());
    }

    public function testNotFoundStateMarksBatchFailed(): void
    {
        $job = $this->seedJob();
        $batch = $this->persistBatch($job, batchIndex: 0, ozonUuid: 'aaaa7777-aaaa-aaaa-aaaa-aaaaaaaaaaa7');

        $this->clientMock->method('pollOneReport')
            ->willReturn(['state' => 'NOT_FOUND', 'raw' => []]);
        $this->clientMock->expects(self::never())->method('fetchReportContent');
        $this->storageMock->expects(self::never())->method('storeBytes');

        $tester = $this->makeCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em->clear();
        $reloaded = $this->batchRepo->find($batch->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdScheduledBatchState::FAILED, $reloaded->getState());
        self::assertStringContainsString('state=NOT_FOUND', (string) $reloaded->getLastError());
        self::assertNotNull($reloaded->getFinishedAt());
    }

    public function testPermanentApiExceptionDuringDownloadMarksBatchFailed(): void
    {
        $job = $this->seedJob();
        $batch = $this->persistBatch($job, batchIndex: 0, ozonUuid: 'aaaa8888-aaaa-aaaa-aaaa-aaaaaaaaaaa8');

        $this->clientMock->method('pollOneReport')
            ->willReturn(['state' => 'OK', 'raw' => []]);
        $this->clientMock->method('fetchReportContent')
            ->willThrowException(new \App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException(
                'Ozon Performance: report download denied (403)',
            ));
        $this->storageMock->expects(self::never())->method('storeBytes');

        $tester = $this->makeCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em->clear();
        $reloaded = $this->batchRepo->find($batch->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdScheduledBatchState::FAILED, $reloaded->getState(), 'Permanent download failure → FAILED, а не IN_FLIGHT');
        self::assertStringStartsWith('Download permanent failure:', (string) $reloaded->getLastError());
        self::assertNotNull($reloaded->getFinishedAt());
        self::assertNull($reloaded->getStoragePath(), 'Файл не должен быть записан');
    }

    public function testNotStartedBatchStaysInFlight(): void
    {
        $job = $this->seedJob();
        $batch = $this->persistBatch($job, batchIndex: 0, ozonUuid: 'aaaa5555-aaaa-aaaa-aaaa-aaaaaaaaaaa5');

        $this->clientMock->method('pollOneReport')
            ->willReturn(['state' => 'NOT_STARTED', 'raw' => []]);
        $this->clientMock->expects(self::never())->method('fetchReportContent');
        $this->storageMock->expects(self::never())->method('storeBytes');

        $tester = $this->makeCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em->clear();
        $reloaded = $this->batchRepo->find($batch->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdScheduledBatchState::IN_FLIGHT, $reloaded->getState());
        self::assertNull($reloaded->getFinishedAt());
        self::assertNull($reloaded->getLastError());
    }

    public function testInProgressBatchAbandonedAfterTimeout(): void
    {
        $job = $this->seedJob();
        $batch = $this->persistBatch($job, batchIndex: 0, ozonUuid: 'aaaa9999-aaaa-aaaa-aaaa-aaaaaaaaaaa9');

        // startedAt = now - 4h: выходит за MAX_AGE_BEFORE_ABANDON_HOURS (3h).
        $batch->setStartedAt(new \DateTimeImmutable('-4 hours'));
        $this->em->flush();

        $this->clientMock->method('pollOneReport')
            ->willReturn(['state' => 'IN_PROGRESS', 'raw' => []]);
        $this->clientMock->expects(self::never())->method('fetchReportContent');

        $tester = $this->makeCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em->clear();
        $reloaded = $this->batchRepo->find($batch->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdScheduledBatchState::ABANDONED, $reloaded->getState());
        self::assertNotNull($reloaded->getFinishedAt());
        self::assertStringContainsString('Abandoned: stuck in IN_PROGRESS', (string) $reloaded->getLastError());
        self::assertStringContainsString('4 hours', (string) $reloaded->getLastError());
    }

    public function testInProgressBatchStaysInFlightWithinTimeout(): void
    {
        $job = $this->seedJob();
        $batch = $this->persistBatch($job, batchIndex: 0, ozonUuid: 'aaaabbbb-aaaa-aaaa-aaaa-aaaaaaaaaaa9');

        // startedAt = now - 1h: в пределах MAX_AGE_BEFORE_ABANDON_HOURS (3h).
        $batch->setStartedAt(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        $this->clientMock->method('pollOneReport')
            ->willReturn(['state' => 'IN_PROGRESS', 'raw' => []]);
        $this->clientMock->expects(self::never())->method('fetchReportContent');

        $tester = $this->makeCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em->clear();
        $reloaded = $this->batchRepo->find($batch->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdScheduledBatchState::IN_FLIGHT, $reloaded->getState(), 'Моложе 3h — не abandon, остаётся IN_FLIGHT');
        self::assertNull($reloaded->getFinishedAt());
        self::assertNull($reloaded->getLastError());
    }

    /**
     * Task-13a: после успешного download'а Poller сразу вызывает
     * {@see \App\MarketplaceAds\Application\ExtractBatchesToRawDocumentsAction::processBatch()}
     * — AdRawDocument должен быть создан и ProcessAdRawDocumentMessage
     * отправлен в `async_pipeline` без ручного клика по кнопке «Обработать».
     *
     * Отличие от остальных интеграционных тестов: НЕ мокаем StorageService —
     * нужен реальный storage, чтобы {@see StorageService::getAbsolutePath()}
     * вернул путь к файлу, который прочитает extractCsvsFromBatch.
     */
    public function testOkStateAutoExtractsRawDocumentAndDispatchesMessage(): void
    {
        // Снимаем мок StorageService, устанавливаем реальный сервис с tmp-root'ом.
        $tmpRoot = sys_get_temp_dir().'/poller-autoextract-'.bin2hex(random_bytes(6));
        mkdir($tmpRoot, 0o775, true);

        try {
            $realStorage = new StorageService($tmpRoot);
            self::getContainer()->set(StorageService::class, $realStorage);

            $job = $this->seedJob();
            $batch = $this->persistBatch($job, batchIndex: 0, ozonUuid: 'aaaa1111-aaaa-aaaa-aaaa-autoext00001');

            $csvBody = "\xEF\xBB\xBF;Кампания по продвижению товаров № 22655731, период 23.04.2026-23.04.2026\n"
                ."sku;spend\n"
                ."sku-1;1.00\n";

            $this->clientMock->method('pollOneReport')
                ->willReturn(['state' => 'OK', 'raw' => []]);
            $this->clientMock->method('fetchReportContent')
                ->willReturn(['body' => $csvBody, 'contentType' => 'text/csv']);

            $tester = $this->makeCommandTester();
            self::assertSame(Command::SUCCESS, $tester->execute([]));

            $this->em->clear();

            $reloaded = $this->batchRepo->find($batch->getId());
            self::assertNotNull($reloaded);
            self::assertSame(AdScheduledBatchState::OK, $reloaded->getState());
            self::assertNotNull($reloaded->getStoragePath());

            /** @var AdRawDocumentRepository $rawRepo */
            $rawRepo = self::getContainer()->get(AdRawDocumentRepository::class);
            $docs = $rawRepo->findBy(['companyId' => self::COMPANY_ID]);
            self::assertCount(1, $docs, 'Poller после download\'а должен создать AdRawDocument без ручного клика');
            self::assertSame(AdRawDocumentStatus::DRAFT, $docs[0]->getStatus());
            self::assertStringStartsWith(
                sprintf("batch_id=%s\nfilename=", $batch->getId()),
                $docs[0]->getRawPayload(),
            );

            /** @var InMemoryTransport $transport */
            $transport = self::getContainer()->get('messenger.transport.async_pipeline');
            $envelopes = $transport->getSent();
            self::assertCount(1, $envelopes);
            $message = $envelopes[0]->getMessage();
            self::assertInstanceOf(ProcessAdRawDocumentMessage::class, $message);
            self::assertSame(self::COMPANY_ID, $message->companyId);
            self::assertSame($docs[0]->getId(), $message->adRawDocumentId);
        } finally {
            $this->rmDirRecursive($tmpRoot);
        }
    }

    /**
     * Auto-extract идемпотентен: повторный вызов processBatch на уже
     * обработанном batch'е → findByBatchAndFilename находит AdRawDocument,
     * createOrFindRawDocument возвращает null → skipped++. Мы моделируем это
     * косвенно: первый tick создаёт doc, затем мы вручную зовём processBatch
     * ещё раз и проверяем, что второй doc не создаётся.
     */
    public function testAutoExtractIsIdempotentOnRepeatedProcessBatchCall(): void
    {
        $tmpRoot = sys_get_temp_dir().'/poller-idempotent-'.bin2hex(random_bytes(6));
        mkdir($tmpRoot, 0o775, true);

        try {
            $realStorage = new StorageService($tmpRoot);
            self::getContainer()->set(StorageService::class, $realStorage);

            $job = $this->seedJob();
            $batch = $this->persistBatch($job, batchIndex: 0, ozonUuid: 'aaaa2222-aaaa-aaaa-aaaa-autoext00002');

            $csvBody = "\xEF\xBB\xBF;Кампания по продвижению товаров № 33333, период 23.04.2026-23.04.2026\n"
                ."sku;spend\n"
                ."sku-1;2.50\n";

            $this->clientMock->method('pollOneReport')
                ->willReturn(['state' => 'OK', 'raw' => []]);
            $this->clientMock->method('fetchReportContent')
                ->willReturn(['body' => $csvBody, 'contentType' => 'text/csv']);

            $tester = $this->makeCommandTester();
            self::assertSame(Command::SUCCESS, $tester->execute([]));

            /** @var AdRawDocumentRepository $rawRepo */
            $rawRepo = self::getContainer()->get(AdRawDocumentRepository::class);
            self::assertCount(1, $rawRepo->findBy(['companyId' => self::COMPANY_ID]));

            // Повторный вызов processBatch на том же batch'е — идемпотентен.
            /** @var \App\MarketplaceAds\Application\ExtractBatchesToRawDocumentsAction $extractAction */
            $extractAction = self::getContainer()->get(
                \App\MarketplaceAds\Application\ExtractBatchesToRawDocumentsAction::class,
            );

            $this->em->clear();
            $reloaded = $this->batchRepo->find($batch->getId());
            self::assertNotNull($reloaded);

            $result = $extractAction->processBatch($reloaded);
            self::assertSame(0, $result['processed']);
            self::assertSame(1, $result['skipped'], 'Второй вызов: existing AdRawDocument → skipped');

            self::assertCount(1, $rawRepo->findBy(['companyId' => self::COMPANY_ID]), 'Дубликата не появилось');
        } finally {
            $this->rmDirRecursive($tmpRoot);
        }
    }

    public function testAutoExtractFailureDoesNotMarkBatchFailed(): void
    {
        // Используем дефолтный storage mock (из parent setUp) — он вернёт
        // пустой absolutePath → extractCsvsFromBatch упадёт с "file missing
        // on disk" → Poller поймает \Throwable и залогирует, batch остаётся OK.
        $job = $this->seedJob();
        $batch = $this->persistBatch($job, batchIndex: 0, ozonUuid: 'aaaa3333-aaaa-aaaa-aaaa-autoext00003');

        $this->clientMock->method('pollOneReport')
            ->willReturn(['state' => 'OK', 'raw' => []]);
        $this->clientMock->method('fetchReportContent')
            ->willReturn(['body' => 'a,b,c', 'contentType' => 'text/csv']);

        $this->storageMock->method('storeBytes')
            ->willReturnCallback(static fn (string $body, string $path): array => [
                'storagePath' => $path,
                'fileHash' => hash('sha256', $body),
                'sizeBytes' => strlen($body),
                'mimeType' => null,
            ]);
        // getAbsolutePath не настроен → возвращает null-like; extract упадёт.

        $tester = $this->makeCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em->clear();
        $reloaded = $this->batchRepo->find($batch->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdScheduledBatchState::OK, $reloaded->getState(), 'Extract fail → batch всё равно OK, fallback через кнопку');

        /** @var AdRawDocumentRepository $rawRepo */
        $rawRepo = self::getContainer()->get(AdRawDocumentRepository::class);
        self::assertCount(0, $rawRepo->findBy(['companyId' => self::COMPANY_ID]));
    }

    public function testTransientErrorOnOneBatchDoesNotBlockOthers(): void
    {
        $job = $this->seedJob();
        $batchA = $this->persistBatch($job, batchIndex: 0, ozonUuid: 'aaaa6661-aaaa-aaaa-aaaa-aaaaaaaaaaa1');
        $batchB = $this->persistBatch($job, batchIndex: 1, ozonUuid: 'aaaa6662-aaaa-aaaa-aaaa-aaaaaaaaaaa2');

        $this->clientMock->method('pollOneReport')
            ->willReturnCallback(static function (string $companyId, string $uuid): array {
                if ('aaaa6661-aaaa-aaaa-aaaa-aaaaaaaaaaa1' === $uuid) {
                    throw new \RuntimeException('Ozon network hiccup');
                }

                return ['state' => 'ERROR', 'raw' => []];
            });

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit, 'Per-batch transient не останавливает цикл');

        $this->em->clear();

        $a = $this->batchRepo->find($batchA->getId());
        self::assertNotNull($a);
        self::assertSame(AdScheduledBatchState::IN_FLIGHT, $a->getState(), 'A остался IN_FLIGHT: transient');
        self::assertNull($a->getLastError());

        $b = $this->batchRepo->find($batchB->getId());
        self::assertNotNull($b);
        self::assertSame(AdScheduledBatchState::FAILED, $b->getState(), 'B обработан независимо от сбоя A');
    }

    private function makeCommandTester(): CommandTester
    {
        $app = new Application(self::$kernel);
        $command = $app->find('app:marketplace-ads:poller');

        return new CommandTester($command);
    }

    private function seedJob(): AdLoadJob
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();

        $this->jobRepo->save($job);
        $this->em->flush();

        return $job;
    }

    private function persistBatch(AdLoadJob $job, int $batchIndex, string $ozonUuid): AdScheduledBatch
    {
        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex($batchIndex)
            ->withCampaignIds(['camp-1', 'camp-2'])
            ->withDateRange(
                new \DateTimeImmutable('2026-03-01'),
                new \DateTimeImmutable('2026-03-10'),
            )
            ->withScheduledAt(new \DateTimeImmutable('-1 hour'))
            ->withState(AdScheduledBatchState::IN_FLIGHT)
            ->withOzonUuid($ozonUuid)
            ->build();

        $this->batchRepo->save($batch);
        $this->em->flush();

        return $batch;
    }

    private function rmDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $this->rmDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function seedCompany(string $companyId, string $ownerId, string $email): Company
    {
        $owner = UserBuilder::aUser()
            ->withId($ownerId)
            ->withEmail($email)
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }
}
