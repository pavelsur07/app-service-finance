<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\MarketplaceAds\Application\Service\OzonReportExtensionDetector;
use App\MarketplaceAds\Entity\AdScheduledBatch;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Shared\Service\Storage\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Poller cron для cron-driven Ozon Performance pipeline (Task-11.6).
 *
 * За один запуск обрабатывает ВСЕ IN_FLIGHT-батчи из `ad_scheduled_batches`:
 *  1) {@see OzonAdClient::pollOneReport()} — `GET /api/client/statistics/{uuid}`
 *     возвращает normalized state (uppercase).
 *  2) по state:
 *      - `OK` / `READY`  → {@see OzonAdClient::fetchReportContent()} → сохранить
 *        файл через {@see StorageService::storeBytes()} → batch в OK;
 *      - `ERROR` / `CANCELLED` → batch в FAILED с причиной;
 *      - `NOT_STARTED` / `IN_PROGRESS` → без изменений (следующий тик попробует);
 *      - unknown / unexpected → лог warning, batch не трогаем.
 *
 * Ozon не лимитирует `GET /statistics/{uuid}`, только `POST /statistics` (см.
 * Scheduler Task-11.5), поэтому Poller обрабатывает весь queue за один тик,
 * а не по одному за инвокейшн.
 *
 * Per-batch try/catch: transient-ошибка на одном батче (сеть, 5xx) не
 * останавливает обработку остальных — батч остаётся IN_FLIGHT, следующий тик
 * попробует снова. Это отличает Poller от Scheduler, где транзакционная
 * обвязка и rollback защищают state-переход одного батча.
 *
 * Инвариант: IN_FLIGHT ⇒ `ozon_uuid IS NOT NULL` — Scheduler гарантирует это
 * в одной транзакции. Sanity-check для защиты от рассинхрона: если обнаружен
 * IN_FLIGHT без uuid, батч переводится в FAILED с явным сообщением.
 *
 * Не подключён в cron в рамках Task-11.6 — ждёт Finalizer (Task-11.7),
 * включится одним релизом.
 */
#[AsCommand(
    name: 'app:marketplace-ads:poller',
    description: 'Polls IN_FLIGHT batches: check state → download file → mark OK/FAILED',
)]
final class AdBatchPollerCommand extends Command
{
    public function __construct(
        private readonly OzonAdClient $ozonClient,
        private readonly AdScheduledBatchRepository $batchRepo,
        private readonly StorageService $storageService,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batches = $this->batchRepo->findAllInFlight();

        if ([] === $batches) {
            $output->writeln('<info>No IN_FLIGHT batches.</info>');

            return self::SUCCESS;
        }

        $output->writeln(sprintf('Processing %d IN_FLIGHT batches', count($batches)));

        $okCount = 0;
        $failedCount = 0;
        $stillInFlight = 0;
        $transientErrors = 0;

        foreach ($batches as $batch) {
            try {
                $result = $this->processBatch($batch);
                match ($result) {
                    'ok' => ++$okCount,
                    'failed' => ++$failedCount,
                    default => ++$stillInFlight,
                };
            } catch (\Throwable $e) {
                // Transient для конкретного батча — логируем, идём дальше.
                // Batch остаётся IN_FLIGHT, следующий poll попробует снова.
                ++$transientErrors;
                $this->logger->warning('Poller: batch processing failed (transient)', [
                    'batchId' => $batch->getId(),
                    'ozonUuid' => $batch->getOzonUuid(),
                    'companyId' => $batch->getCompanyId(),
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            }
        }

        $output->writeln(sprintf(
            '<info>Totals: ok=%d failed=%d in_flight=%d transient_errors=%d</info>',
            $okCount,
            $failedCount,
            $stillInFlight,
            $transientErrors,
        ));

        return self::SUCCESS;
    }

    /**
     * @return string one of: 'ok' | 'failed' | 'in_flight'
     */
    private function processBatch(AdScheduledBatch $batch): string
    {
        $uuid = $batch->getOzonUuid();

        if (null === $uuid) {
            // Инвариант IN_FLIGHT → ozon_uuid гарантируется Scheduler'ом
            // внутри одной транзакции. Сюда попадаем только при рассинхроне;
            // переводим в FAILED, чтобы batch не висел вечно.
            $this->logger->error('Poller: IN_FLIGHT batch without ozon_uuid — marking FAILED', [
                'batchId' => $batch->getId(),
                'jobId' => $batch->getJobId(),
                'companyId' => $batch->getCompanyId(),
            ]);

            $batch->setState(AdScheduledBatchState::FAILED);
            $batch->setLastError('Invariant violation: IN_FLIGHT without ozon_uuid');
            $batch->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();

            return 'failed';
        }

        try {
            $result = $this->ozonClient->pollOneReport($batch->getCompanyId(), $uuid);
        } catch (OzonPermanentApiException $e) {
            $batch->setState(AdScheduledBatchState::FAILED);
            $batch->setLastError('Poll permanent failure: '.$e->getMessage());
            $batch->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();

            $this->logger->error('Poller: permanent poll failure, marking FAILED', [
                'batchId' => $batch->getId(),
                'ozonUuid' => $uuid,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }

        $state = $result['state']; // уже uppercase (нормализован pollOneReport'ом)

        switch ($state) {
            case 'OK':
            case 'READY':
                try {
                    $this->downloadAndFinalize($batch);
                } catch (OzonPermanentApiException $e) {
                    // 403 / revoked credentials во время скачивания — permanent.
                    // Без этого catch'а исключение улетало бы в outer per-batch
                    // \Throwable и писалось как transient: batch висел бы IN_FLIGHT
                    // и следующий тик ретраил тот же 403 бесконечно.
                    $batch->setState(AdScheduledBatchState::FAILED);
                    $batch->setLastError('Download permanent failure: '.$e->getMessage());
                    $batch->setFinishedAt(new \DateTimeImmutable());
                    $this->em->flush();

                    $this->logger->error('Poller: permanent failure during download', [
                        'batchId' => $batch->getId(),
                        'ozonUuid' => $uuid,
                        'error' => $e->getMessage(),
                    ]);

                    return 'failed';
                }

                return 'ok';

            case 'ERROR':
            case 'CANCELLED':
            case 'NOT_FOUND':
                // NOT_FOUND = UUID пропал на стороне Ozon (expired / GC'ed /
                // invalid). Без терминализации батч висел бы IN_FLIGHT вечно —
                // тот же поведение, что и в старом `OzonAdReportPoller`.
                $batch->setState(AdScheduledBatchState::FAILED);
                $batch->setLastError(sprintf('Ozon reported state=%s', $state));
                $batch->setFinishedAt(new \DateTimeImmutable());
                $this->em->flush();

                $this->logger->info('Poller: batch marked FAILED', [
                    'batchId' => $batch->getId(),
                    'ozonUuid' => $uuid,
                    'state' => $state,
                ]);

                return 'failed';

            case 'NOT_STARTED':
            case 'IN_PROGRESS':
                // Ждём — следующий poll попробует снова.
                $this->logger->debug('Poller: batch still in progress', [
                    'batchId' => $batch->getId(),
                    'ozonUuid' => $uuid,
                    'state' => $state,
                ]);

                return 'in_flight';

            default:
                // Неожиданное значение state — не трогаем batch, логируем
                // warning, чтобы операторы заметили unknown-state от Ozon.
                $this->logger->warning('Poller: unexpected Ozon state', [
                    'batchId' => $batch->getId(),
                    'ozonUuid' => $uuid,
                    'state' => $state,
                ]);

                return 'in_flight';
        }
    }

    private function downloadAndFinalize(AdScheduledBatch $batch): void
    {
        $companyId = $batch->getCompanyId();
        $uuid = (string) $batch->getOzonUuid();

        $response = $this->ozonClient->fetchReportContent($companyId, $uuid);
        $body = $response['body'];
        $contentType = $response['contentType'] ?? null;

        $extension = OzonReportExtensionDetector::detect($body, $contentType);

        $relativePath = sprintf(
            'marketplace-ads/%s/%s.%s',
            $companyId,
            $uuid,
            $extension,
        );

        $stored = $this->storageService->storeBytes($body, $relativePath);

        $batch->setState(AdScheduledBatchState::OK);
        $batch->setStoragePath((string) $stored['storagePath']);
        $batch->setFileHash((string) $stored['fileHash']);
        $batch->setFileSize((int) $stored['sizeBytes']);
        $batch->setFinishedAt(new \DateTimeImmutable());

        $this->em->flush();

        $this->logger->info('Poller: batch completed, file saved', [
            'batchId' => $batch->getId(),
            'companyId' => $companyId,
            'ozonUuid' => $uuid,
            'storagePath' => $stored['storagePath'],
            'fileSize' => $stored['sizeBytes'],
            'extension' => $extension,
        ]);
    }
}
