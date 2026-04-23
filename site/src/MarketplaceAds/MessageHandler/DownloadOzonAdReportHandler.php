<?php

declare(strict_types=1);

namespace App\MarketplaceAds\MessageHandler;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\Service\AdLoadJobFinalizer;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\DownloadOzonAdReportMessage;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use App\Shared\Service\Storage\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Async-обработчик {@see DownloadOzonAdReportMessage}.
 *
 * Шаги (task-8, 23.04.2026 — парсинг временно отключён):
 *  1) загрузить pending-отчёт IDOR-safe (findByIdAndCompany);
 *  2) идемпотентно no-op'нуть, если отчёт уже финализирован (retry / race
 *     с параллельным воркером);
 *  3) скачать raw-тело отчёта через {@see OzonAdClient::fetchReportContent()}
 *     (без парсинга CSV);
 *  4) определить расширение (csv / zip) по Content-Type → magic bytes;
 *  5) сохранить raw-байты как файл в `marketplace-ads/<companyId>/<uuid>.<ext>`;
 *  6) upsert AdRawDocument'ов на каждый день диапазона pending-отчёта с
 *     `raw_payload='{}'` (контент не дублируется в БД) и заполненным
 *     storagePath / fileHash / fileSizeBytes;
 *  7) flush UoW (persist + bronze-metadata) одним запросом;
 *  8) markFinalized(OK) на pending-отчёте;
 *  9) ProcessAdRawDocumentMessage **НЕ** диспатчится — парсинг временно
 *     отключён, файл доступен оператору через
 *     GET /marketplace-ads/raw-documents/{id}/download. Возобновим отдельной
 *     задачей.
 *
 * Политика ошибок:
 *  - OzonPermanentApiException (403, missing credentials): permanent denial →
 *    markFinalized(ERROR) + UnrecoverableMessageHandlingException (не ретраит).
 *  - Прочие \Throwable (5xx, сеть, JSON-ошибки): transient → rethrow, Messenger
 *    ретраит по async_pipeline schedule (3× × 5/10/20s).
 *  - "Already finalized" / "not found" кейсы — не ошибки, просто no-op ACK.
 */
#[AsMessageHandler]
final class DownloadOzonAdReportHandler
{
    public function __construct(
        private readonly OzonAdPendingReportRepository $pendingRepo,
        private readonly AdRawDocumentRepository $rawRepo,
        private readonly OzonAdClient $client,
        private readonly EntityManagerInterface $em,
        private readonly StorageService $storageService,
        private readonly AdLoadJobFinalizer $finalizer,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DownloadOzonAdReportMessage $message): void
    {
        $now = new \DateTimeImmutable();

        $pending = $this->pendingRepo->findByIdAndCompany(
            $message->pendingReportId,
            $message->companyId,
        );

        if (null === $pending) {
            // Запись удалена или companyId не совпадает (IDOR-guard). Не ошибка —
            // ACK и выходим: возможно, оператор вручную удалил pending-отчёт
            // или мы ловим чужой message из сломанной очереди.
            $this->logger->warning('DownloadOzonAdReportMessage: pending-отчёт не найден или принадлежит другой company, сообщение проигнорировано', [
                'pendingReportId' => $message->pendingReportId,
                'companyId' => $message->companyId,
            ]);

            return;
        }

        if (null !== $pending->getFinalizedAt()) {
            // Уже обработан — идемпотентный no-op. Покрывает две гонки:
            //  - Messenger retry после успешного прогона (commit прошёл,
            //    ACK не дошёл до брокера → re-delivery того же message);
            //  - параллельный worker увидел тот же state=OK и уже всё скачал.
            $this->logger->info('DownloadOzonAdReportMessage: pending-отчёт уже финализирован, download пропущен', [
                'pendingReportId' => $pending->getId(),
                'companyId' => $pending->getCompanyId(),
                'reportUuid' => $pending->getOzonUuid(),
                'state' => $pending->getState(),
            ]);

            return;
        }

        try {
            $response = $this->client->fetchReportContent(
                $pending->getCompanyId(),
                $pending->getOzonUuid(),
            );
        } catch (OzonPermanentApiException $e) {
            // 403 / отсутствующие credentials — ретрай не поможет. Финализируем
            // pending-отчёт как ERROR и бросаем Unrecoverable, чтобы Messenger
            // не возвращал message в очередь.
            $this->pendingRepo->markFinalized(
                $pending->getCompanyId(),
                $pending->getOzonUuid(),
                OzonAdPendingReportState::ERROR,
                'Download permanent failure: '.$e->getMessage(),
            );

            $this->logger->warning('DownloadOzonAdReportMessage: permanent Ozon API denial', [
                'pendingReportId' => $pending->getId(),
                'companyId' => $pending->getCompanyId(),
                'reportUuid' => $pending->getOzonUuid(),
                'error' => $e->getMessage(),
            ]);

            throw new UnrecoverableMessageHandlingException(
                'DownloadOzonAdReportMessage: Ozon permanent failure — '.$e->getMessage(),
                0,
                $e,
            );
        }

        $body = $response['body'];
        $contentType = $response['contentType'] ?? '';
        $extension = $this->detectExtension((string) $contentType, $body);

        $relativePath = sprintf(
            'marketplace-ads/%s/%s.%s',
            $pending->getCompanyId(),
            $pending->getOzonUuid(),
            $extension,
        );

        $stored = $this->storageService->storeBytes($body, $relativePath);
        $storagePath = (string) $stored['storagePath'];
        $fileHash = (string) $stored['fileHash'];
        $sizeBytes = (int) $stored['sizeBytes'];

        // Для каждого дня pending-диапазона — upsert AdRawDocument. Один файл
        // на диске, N документов в БД (по дню). Парсинг отключён, поэтому
        // rawPayload='{}' (поле NOT NULL в схеме), контент не дублируется.
        /** @var list<AdRawDocument> $documents */
        $documents = [];
        $dateFrom = $pending->getDateFrom()->setTime(0, 0);
        $dateTo = $pending->getDateTo()->setTime(0, 0);

        for ($date = $dateFrom; $date <= $dateTo; $date = $date->modify('+1 day')) {
            $existing = $this->rawRepo->findByMarketplaceAndDate(
                $pending->getCompanyId(),
                MarketplaceType::OZON->value,
                $date,
            );

            if (null === $existing) {
                $doc = new AdRawDocument(
                    $pending->getCompanyId(),
                    MarketplaceType::OZON,
                    $date,
                    '{}',
                );
                $this->rawRepo->save($doc);
                $documents[] = $doc;
            } else {
                // updatePayload() сбросит статус в DRAFT — ожидаемое поведение
                // при re-download, контент документа устарел.
                $existing->updatePayload('{}');
                $documents[] = $existing;
            }
        }

        foreach ($documents as $doc) {
            $doc->setFileStorage($storagePath, $fileHash, $sizeBytes);
        }

        $this->logger->info('DownloadOzonAdReportMessage: отчёт сохранён как файл', [
            'pendingReportId' => $pending->getId(),
            'companyId' => $pending->getCompanyId(),
            'reportUuid' => $pending->getOzonUuid(),
            'storagePath' => $storagePath,
            'sizeBytes' => $sizeBytes,
            'extension' => $extension,
            'documentsLinked' => count($documents),
        ]);

        // Единый flush для persist новых AdRawDocument + bronze metadata.
        $this->em->flush();

        // Финализируем pending-отчёт: OK без errorMessage. markFinalized()
        // идемпотентен (guard `finalized_at IS NULL`), так что при повторном
        // дисптаче этой цепочки вызов просто вернёт 0 affected rows.
        $this->pendingRepo->markFinalized(
            $pending->getCompanyId(),
            $pending->getOzonUuid(),
            OzonAdPendingReportState::OK,
        );

        // ПАРСИНГ ВРЕМЕННО ОТКЛЮЧЁН (task-8, 23.04.2026):
        // Файл сохранён на диск, доступен через
        // GET /marketplace-ads/raw-documents/{id}/download. Пока парсинг не
        // включён, документы остаются в DRAFT, и AdLoadJobFinalizer не
        // финализирует job (ждёт терминальных статусов). Zero-docs edge case
        // сохранён — job с пустым диапазоном (напр. dateTo < dateFrom) закроется
        // здесь же.
        if ([] === $documents && null !== $pending->getJobId()) {
            $this->logger->info('DownloadOzonAdReportMessage: zero days in pending range — finalizing job directly', [
                'pendingReportId' => $pending->getId(),
                'jobId' => $pending->getJobId(),
            ]);
            $this->finalizer->tryFinalize(
                $pending->getJobId(),
                $pending->getCompanyId(),
            );
        }

        $this->logger->info('DownloadOzonAdReportMessage: отчёт обработан', [
            'pendingReportId' => $pending->getId(),
            'companyId' => $pending->getCompanyId(),
            'reportUuid' => $pending->getOzonUuid(),
            'documentsUpserted' => count($documents),
            'processedAt' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Определяет расширение файла отчёта Ozon.
     *
     * Первый источник истины — Content-Type header. При неоднозначности
     * (application/octet-stream, text/plain, пустой заголовок) — проверяем
     * magic bytes первых 4 байт. PK\x03\x04 → zip, иначе csv.
     */
    private function detectExtension(string $contentType, string $body): string
    {
        $ct = strtolower(trim(explode(';', $contentType)[0] ?? ''));

        if (\in_array($ct, ['application/zip', 'application/x-zip-compressed'], true)) {
            return 'zip';
        }

        if (\in_array($ct, ['text/csv', 'application/csv'], true)) {
            return 'csv';
        }

        if (\strlen($body) >= 4 && "PK\x03\x04" === substr($body, 0, 4)) {
            return 'zip';
        }

        return 'csv';
    }
}
