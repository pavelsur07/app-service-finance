<?php

declare(strict_types=1);

namespace App\MarketplaceAds\MessageHandler;

use App\MarketplaceAds\Application\ProcessAdRawDocumentAction;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Exception\AdRawDocumentAlreadyProcessedException;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdChunkProgressRepositoryInterface;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use App\MarketplaceAds\Repository\AdRawDocumentRepositoryInterface;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async-обработчик {@see ProcessAdRawDocumentMessage}.
 *
 * Контракт error-flow (per-document FAILED):
 *  - исключение из Action → документ помечается FAILED с processing_error,
 *    исключение пере­брасывается для retry-стратегии Messenger;
 *  - Action оставил документ в DRAFT (частичная неудача — например, нет
 *    листингов по части parentSku) → handler сам помечает документ FAILED
 *    и возвращается БЕЗ throw: повторный запуск приведёт к тому же результату;
 *  - {@see AdRawDocumentAlreadyProcessedException} (race / документ удалён) —
 *    поглощается, финализация job'а не запускается (документ уже не «наш»).
 *
 * Финализация AdLoadJob:
 *  - после любого терминального исхода (PROCESSED/FAILED) handler пытается
 *    финализировать job, который покрывает дату документа: если все чанки
 *    зафиксированы И все документы за период в терминальном статусе, job
 *    переводится в COMPLETED (при отсутствии failed-документов) или FAILED
 *    (если хотя бы один документ failed). Решение принимается по COUNT(*)
 *    в БД, а не по локальным счётчикам — это устойчиво к параллельным воркерам.
 */
#[AsMessageHandler]
final class ProcessAdRawDocumentHandler
{
    public function __construct(
        private readonly ProcessAdRawDocumentAction $action,
        private readonly AdRawDocumentRepositoryInterface $adRawDocumentRepository,
        private readonly AdLoadJobRepositoryInterface $adLoadJobRepository,
        private readonly AdChunkProgressRepositoryInterface $adChunkProgressRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppLogger $logger,
    ) {
    }

    public function __invoke(ProcessAdRawDocumentMessage $message): void
    {
        try {
            ($this->action)($message->companyId, $message->adRawDocumentId);
            $this->entityManager->flush();
        } catch (AdRawDocumentAlreadyProcessedException $e) {
            // Гонка: документ уже не в DRAFT либо удалён. Ретрай Messenger'а
            // здесь только шумит в failed-queue. Финализацию job'а тоже не
            // запускаем — документ уже не наша забота, его исход обработает
            // тот воркер, который реально перевёл его в терминальный статус.
            $this->logger->info(
                'AdRawDocument обработан параллельно или удалён, повтор не нужен',
                [
                    'companyId' => $message->companyId,
                    'adRawDocumentId' => $message->adRawDocumentId,
                    'reason' => $e->getMessage(),
                ],
            );

            return;
        } catch (\Throwable $e) {
            // markFailedWithReason — raw DBAL минуя UoW: переживает закрытый
            // после wrapInTransaction EntityManager (Connection остаётся живой).
            $this->adRawDocumentRepository->markFailedWithReason(
                $message->adRawDocumentId,
                $message->companyId,
                $e::class.': '.$e->getMessage(),
            );

            // tryFinalizeJobForDocument использует ORM-запросы (findByIdAndCompany,
            // findActiveJobCoveringDate). Если исходное исключение пришло из
            // EntityManager::wrapInTransaction, Doctrine закрыл EM — secondary
            // "EntityManager is closed" замаскировал бы $e и отправил бы в
            // failed-queue бесполезное сообщение. Глотаем secondary и логируем:
            // следующий успешный message по документу этого job'а всё равно
            // добьёт финализацию.
            try {
                $this->tryFinalizeJobForDocument($message->companyId, $message->adRawDocumentId);
            } catch (\Throwable $secondary) {
                $this->logger->error(
                    'Финализация job пропущена после ошибки обработки документа',
                    $secondary,
                    [
                        'companyId' => $message->companyId,
                        'adRawDocumentId' => $message->adRawDocumentId,
                        'originalException' => $e::class.': '.$e->getMessage(),
                    ],
                );
            }

            throw $e;
        }

        $document = $this->adRawDocumentRepository->findByIdAndCompany(
            $message->adRawDocumentId,
            $message->companyId,
        );

        if (null === $document) {
            $this->logger->warning('AdRawDocument исчез после успешной обработки', [
                'companyId' => $message->companyId,
                'adRawDocumentId' => $message->adRawDocumentId,
            ]);

            return;
        }

        // Action завершился без исключения, но оставил документ в DRAFT —
        // это «частичный успех» (часть SKU не сматчилась). Без явного перевода
        // в FAILED job никогда не финализируется: COUNT processed+failed
        // останется меньше total.
        if (AdRawDocumentStatus::DRAFT === $document->getStatus()) {
            $this->adRawDocumentRepository->markFailedWithReason(
                $message->adRawDocumentId,
                $message->companyId,
                'Action left document in DRAFT (partial processing failure)',
            );
            $this->logger->warning(
                'AdRawDocument остался в DRAFT после Action — помечен FAILED',
                [
                    'companyId' => $message->companyId,
                    'adRawDocumentId' => $message->adRawDocumentId,
                ],
            );
            $this->tryFinalizeJobForDocument($message->companyId, $message->adRawDocumentId);

            return;
        }

        $this->tryFinalizeJobForDocument($message->companyId, $message->adRawDocumentId);
    }

    /**
     * Находит активный job, покрывающий дату документа, и пытается финализировать.
     *
     * Документ перечитывается заново — статус мог измениться внутри __invoke
     * (DRAFT → FAILED). Если job не найден (например, уже завершён другим воркером
     * или дата вне всех активных диапазонов) — тихий no-op.
     */
    private function tryFinalizeJobForDocument(string $companyId, string $documentId): void
    {
        $document = $this->adRawDocumentRepository->findByIdAndCompany($documentId, $companyId);
        if (null === $document) {
            return;
        }

        $job = $this->adLoadJobRepository->findActiveJobCoveringDate(
            $companyId,
            $document->getMarketplace(),
            $document->getReportDate(),
        );
        if (null === $job) {
            return;
        }

        $this->tryFinalizeJob($job->getId(), $companyId);
    }

    /**
     * Идемпотентная попытка финализации job'а.
     *
     * Все условия проверяются по актуальному состоянию БД (COUNT по чанкам и
     * документам), что позволяет нескольким параллельным воркерам безопасно
     * вызывать метод одновременно — markCompleted/markFailed имеют SQL-guard
     * `status IN (pending, running)` и обновят строку только один раз.
     */
    private function tryFinalizeJob(string $jobId, string $companyId): void
    {
        $job = $this->adLoadJobRepository->findByIdAndCompany($jobId, $companyId);
        if (null === $job) {
            return;
        }

        if (AdLoadJobStatus::RUNNING !== $job->getStatus()) {
            return;
        }

        $completedChunks = $this->adChunkProgressRepository->countCompletedChunks($jobId, $companyId);
        if ($completedChunks < $job->getChunksTotal()) {
            return;
        }

        $marketplaceValue = $job->getMarketplace()->value;
        $dateFrom = $job->getDateFrom();
        $dateTo = $job->getDateTo();

        $totalDocs = $this->adRawDocumentRepository->countByCompanyMarketplaceAndDateRange(
            $companyId,
            $marketplaceValue,
            $dateFrom,
            $dateTo,
        );
        $processedDocs = $this->adRawDocumentRepository->countByCompanyMarketplaceAndDateRange(
            $companyId,
            $marketplaceValue,
            $dateFrom,
            $dateTo,
            AdRawDocumentStatus::PROCESSED,
        );
        $failedDocs = $this->adRawDocumentRepository->countByCompanyMarketplaceAndDateRange(
            $companyId,
            $marketplaceValue,
            $dateFrom,
            $dateTo,
            AdRawDocumentStatus::FAILED,
        );

        // Остались DRAFT-документы — финализацию делать рано: их добьёт
        // следующий вызов handler'а после обработки оставшихся документов.
        if ($processedDocs + $failedDocs < $totalDocs) {
            return;
        }

        if (0 === $failedDocs) {
            $affected = $this->adLoadJobRepository->markCompleted($jobId, $companyId);
            if ($affected > 0) {
                $this->logger->info('AdLoadJob completed', [
                    'jobId' => $jobId,
                    'companyId' => $companyId,
                    'totalDocs' => $totalDocs,
                ]);
            }

            return;
        }

        $reason = sprintf('Partial failure: %d of %d documents failed', $failedDocs, $totalDocs);
        $affected = $this->adLoadJobRepository->markFailed($jobId, $companyId, $reason);
        if ($affected > 0) {
            $this->logger->warning('AdLoadJob finalized with failures', [
                'jobId' => $jobId,
                'companyId' => $companyId,
                'totalDocs' => $totalDocs,
                'failedDocs' => $failedDocs,
                'processedDocs' => $processedDocs,
            ]);
        }
    }
}
