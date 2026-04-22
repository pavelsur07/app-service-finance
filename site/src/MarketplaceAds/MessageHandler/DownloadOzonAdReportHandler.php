<?php

declare(strict_types=1);

namespace App\MarketplaceAds\MessageHandler;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\Service\AdLoadJobFinalizer;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonReportDownload;
use App\MarketplaceAds\Message\DownloadOzonAdReportMessage;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use App\Shared\Service\Storage\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Async-обработчик {@see DownloadOzonAdReportMessage}.
 *
 * Диспатчится {@see \App\MarketplaceAds\Application\Service\OzonAdReportPoller}
 * после перехода pending-отчёта в state=OK/READY. Ответственность
 * handler'а — завершить ингест отчёта:
 *  1) загрузить pending-отчёт IDOR-safe (findByIdAndCompany);
 *  2) идемпотентно no-op'нуть, если отчёт уже финализирован (retry / race
 *     с параллельным воркером);
 *  3) скачать CSV через {@see OzonAdClient::downloadAndConvertReport()};
 *  4) upsert AdRawDocument'ов за каждый день из отчёта;
 *  5) сохранить bronze-файл (один отчёт = один download = одна запись bronze);
 *  6) flush UoW (persist + bronze-metadata) одним запросом;
 *  7) markFinalized(OK) на pending-отчёте;
 *  8) диспатч ProcessAdRawDocumentMessage за каждый созданный/обновлённый
 *     документ (fan-out строго ПОСЛЕ flush, иначе per-document handler
 *     может стартовать до появления документа в БД).
 *
 * Политика ошибок:
 *  - OzonPermanentApiException (403, missing credentials): permanent denial →
 *    markFinalized(ERROR) + UnrecoverableMessageHandlingException (не ретраит).
 *  - Прочие \Throwable (5xx, сеть, JSON-ошибки): transient → rethrow, Messenger
 *    ретраит по async_pipeline schedule (3× × 5/10/20s).
 *  - "Already finalized" / "not found" кейсы — не ошибки, просто no-op ACK.
 *
 * Handler НЕ вызывает AdLoadJobFinalizer::tryFinalize(): финализация
 * привязана к per-document processing (ProcessAdRawDocumentHandler) —
 * дублировать вызов здесь сломало бы единственный-источник-правды инвариант
 * про счётчики AdLoadJob.
 */
#[AsMessageHandler]
final class DownloadOzonAdReportHandler
{
    public function __construct(
        private readonly OzonAdPendingReportRepository $pendingRepo,
        private readonly AdRawDocumentRepository $rawRepo,
        private readonly OzonAdClient $client,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
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
            $result = $this->client->downloadAndConvertReport(
                $pending->getCompanyId(),
                $pending->getOzonUuid(),
                $pending->getCampaignIds(),
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

        /** @var array<string, array{campaigns: list<array{campaign_id: string, campaign_name: string, rows: list<array{sku: string, spend: string, views: int, clicks: int}>}>}> $resultByDate */
        $resultByDate = $result['resultByDate'];
        /** @var list<OzonReportDownload> $downloads */
        $downloads = $result['downloads'];

        /** @var list<AdRawDocument> $documents */
        $documents = [];
        $skippedDays = 0;

        foreach ($resultByDate as $dateString => $payload) {
            $reportDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $dateString);
            if (false === $reportDate) {
                ++$skippedDays;
                $this->logger->error('DownloadOzonAdReportMessage: invalid date key in Ozon result, day skipped', [
                    'pendingReportId' => $pending->getId(),
                    'companyId' => $pending->getCompanyId(),
                    'dateKey' => $dateString,
                ]);
                continue;
            }

            $json = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            if (false === $json) {
                ++$skippedDays;
                $this->logger->error('DownloadOzonAdReportMessage: json_encode failed for day payload, day skipped', [
                    'pendingReportId' => $pending->getId(),
                    'companyId' => $pending->getCompanyId(),
                    'date' => $reportDate->format('Y-m-d'),
                    'jsonError' => json_last_error_msg(),
                ]);
                continue;
            }

            $existing = $this->rawRepo->findByMarketplaceAndDate(
                $pending->getCompanyId(),
                MarketplaceType::OZON->value,
                $reportDate,
            );

            if (null === $existing) {
                $doc = new AdRawDocument(
                    $pending->getCompanyId(),
                    MarketplaceType::OZON,
                    $reportDate,
                    $json,
                );
                $this->rawRepo->save($doc);
                $documents[] = $doc;
            } else {
                // updatePayload() сам сбрасывает status в DRAFT — см. паттерн
                // в FetchOzonAdStatisticsHandler::__invoke().
                $existing->updatePayload($json);
                $documents[] = $existing;
            }
        }

        // Bronze-слой: один download = один физический файл, все документы
        // отчёта ссылаются на него (file_hash / file_size одинаковый).
        // Для async-poll invariant "один UUID = один download" соблюдается
        // по построению (poll-cron не батчит, один UUID = один Ozon-отчёт).
        if (1 === count($downloads) && [] !== $documents) {
            $firstDownload = $downloads[0];
            $extension = $firstDownload->wasZip ? 'zip' : 'csv';
            $relativePath = sprintf(
                'companies/%s/marketplace-ads/ozon/bronze/%s/%s.%s',
                $pending->getCompanyId(),
                $pending->getDateFrom()->format('Y-m-d'),
                $firstDownload->reportUuid,
                $extension,
            );

            $stored = $this->storageService->storeBytes($firstDownload->rawBytes, $relativePath);
            $size = (int) $stored['sizeBytes'];

            foreach ($documents as $doc) {
                $doc->setFileStorage($stored['storagePath'], $stored['fileHash'], $size);
            }

            $this->logger->info('DownloadOzonAdReportMessage: bronze-файл сохранён', [
                'pendingReportId' => $pending->getId(),
                'companyId' => $pending->getCompanyId(),
                'reportUuid' => $firstDownload->reportUuid,
                'storagePath' => $stored['storagePath'],
                'sizeBytes' => $size,
                'wasZip' => $firstDownload->wasZip,
                'documentsLinked' => count($documents),
            ]);
        } elseif (1 !== count($downloads)) {
            // downloadAndConvertReport гарантирует ровно один download, но
            // логируем на случай регрессии контракта (например, если в
            // будущем client начнёт re-batch'ить).
            $this->logger->warning('DownloadOzonAdReportMessage: unexpected multi-batch download in async flow', [
                'pendingReportId' => $pending->getId(),
                'batchCount' => count($downloads),
            ]);
        }

        // Единый flush для persist новых AdRawDocument + bronze metadata.
        // ОБЯЗАН завершиться до dispatch ProcessAdRawDocumentMessage — иначе
        // per-document handler может не найти документ в БД (race-condition).
        $this->em->flush();

        // Финализируем pending-отчёт: OK без errorMessage. markFinalized()
        // идемпотентен (guard `finalized_at IS NULL`), так что при повторном
        // дисптаче этой цепочки вызов просто вернёт 0 affected rows.
        $this->pendingRepo->markFinalized(
            $pending->getCompanyId(),
            $pending->getOzonUuid(),
            OzonAdPendingReportState::OK,
        );

        foreach ($documents as $doc) {
            $this->bus->dispatch(new ProcessAdRawDocumentMessage(
                $pending->getCompanyId(),
                $doc->getId(),
            ));
        }

        // Zero-docs edge case: если Ozon вернул пустой отчёт, ни одного
        // ProcessAdRawDocumentMessage не будет, и per-document trigger'а
        // финализации job'а не произойдёт. В старом sync-flow этот случай
        // покрывал FetchOzonAdStatisticsHandler прямым вызовом tryFinalize;
        // в async-flow мы узнаём факт пустого отчёта только здесь, поэтому
        // финализируем job напрямую. tryFinalize идемпотентен (считает
        // processed vs total), так что лишнего вызова не произойдёт.
        if ([] === $documents && null !== $pending->getJobId()) {
            $this->logger->info('DownloadOzonAdReportMessage: zero docs in report — finalizing job directly', [
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
            'daysSkipped' => $skippedDays,
            'processedAt' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
