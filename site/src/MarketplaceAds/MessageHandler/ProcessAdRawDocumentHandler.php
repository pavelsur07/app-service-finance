<?php

declare(strict_types=1);

namespace App\MarketplaceAds\MessageHandler;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\ProcessAdRawDocumentAction;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Exception\AdRawDocumentAlreadyProcessedException;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\AppLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async-обработчик {@see ProcessAdRawDocumentMessage}.
 *
 * Обязанности:
 *  - Не предполагает Request/Session/Security (CLI worker context).
 *  - Всегда перечитывает AdRawDocument по id + companyId: между dispatch и handler
 *    состояние документа могло измениться.
 *  - Идемпотентен: если документ уже обработан (не в DRAFT) — молча возвращается.
 *  - Инкрементирует AdLoadJob.{processedDays|failedDays} и триггерит финализацию
 *    задания, когда все чанки выгружены и все документы обработаны.
 *
 * Финализация AdLoadJob ({@see self::tryFinalizeJob}) — condition:
 *   chunksCompleted >= chunksTotal
 *   AND COUNT(AdRawDocument в [dateFrom; dateTo]) == processedDays + failedDays
 *
 * loadedDays в условие не входит: он coverage-based (см. FetchOzonAdStatisticsHandler)
 * и может overshoot'ить при retry оркестратора. COUNT по AdRawDocument идемпотентен
 * благодаря UniqueConstraint(company_id, marketplace, report_date).
 */
#[AsMessageHandler]
final class ProcessAdRawDocumentHandler
{
    public function __construct(
        private readonly AdRawDocumentRepository $rawDocumentRepository,
        private readonly ProcessAdRawDocumentAction $processAction,
        private readonly AdLoadJobRepositoryInterface $adLoadJobRepository,
        private readonly AppLogger $logger,
    ) {
    }

    public function __invoke(ProcessAdRawDocumentMessage $message): void
    {
        $rawDocument = $this->rawDocumentRepository->findByIdAndCompany(
            $message->adRawDocumentId,
            $message->companyId,
        );

        if (null === $rawDocument) {
            $this->logger->warning('AdRawDocument не найден при async-обработке', [
                'companyId' => $message->companyId,
                'adRawDocumentId' => $message->adRawDocumentId,
            ]);

            return;
        }

        if (AdRawDocumentStatus::DRAFT !== $rawDocument->getStatus()) {
            $this->logger->info('AdRawDocument уже обработан, повторный запуск пропущен', [
                'companyId' => $message->companyId,
                'adRawDocumentId' => $message->adRawDocumentId,
                'status' => $rawDocument->getStatus()->value,
            ]);

            return;
        }

        // Сохраняем атрибуты документа ДО вызова Action: marketplace/reportDate
        // нужны при поиске job'а на любой ветке (успех / частичный / ошибка),
        // а в ветке ошибки (rollback транзакции) entity может быть в detached-состоянии.
        $marketplace = $rawDocument->getMarketplace();
        $reportDate = $rawDocument->getReportDate();

        try {
            ($this->processAction)($message->companyId, $message->adRawDocumentId);
        } catch (AdRawDocumentAlreadyProcessedException $e) {
            // Гонка состояний: другой worker обработал документ между pre-check и
            // вызовом Action (или документ удалили). Мы НЕ инкрементируем
            // processed/failed — это сделает тот воркер, который реально обработал.
            // Ловим специфический тип исключения, чтобы баги конфигурации (например,
            // отсутствие парсера — \RuntimeException) не поглощались молча.
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
            $this->logger->error(
                'Ошибка обработки AdRawDocument',
                $e,
                [
                    'companyId' => $message->companyId,
                    'adRawDocumentId' => $message->adRawDocumentId,
                ],
            );

            // Инкрементим failed_days ДО rethrow: состояние прогресса job'а
            // должно отражать факт текущей ошибки (UI/мониторинг), даже если
            // Messenger уйдёт в retry. На retry счётчик может overshoot'нуть
            // — принимаем этот UX-компромисс сознательно.
            //
            // НО: в error-ветке НЕ вызываем tryFinalizeJob. Иначе overshoot
            // failed_days мог бы дотянуть (processed+failed) до COUNT(raw)
            // раньше, чем retry успел отработать, и job был бы помечен FAILED,
            // даже если последующий retry документа succeed'ит. Финализация
            // разрешается только из success/partial-branch'ей, где документ
            // точно вышел из DRAFT (PROCESSED или остался DRAFT после
            // bez-exception прогона — обе ветки терминальны с точки зрения
            // дальнейшей обработки сообщения).
            $this->incrementFailedOnly($message->companyId, $marketplace, $reportDate);

            throw $e;
        }

        // Перечитываем документ: Action мог оставить его в DRAFT при частичном
        // успехе (часть SKU не смапилась на листинги) — транзакция Action в этом
        // случае не падает, но документ остаётся недообработанным.
        $afterProcessing = $this->rawDocumentRepository->findByIdAndCompany(
            $message->adRawDocumentId,
            $message->companyId,
        );

        if (null === $afterProcessing) {
            // Крайне маловероятно: документ был, Action отработал без исключений,
            // но сейчас его нет. Защита, а не нормальная ветка.
            $this->logger->warning('AdRawDocument исчез после обработки Action', [
                'companyId' => $message->companyId,
                'adRawDocumentId' => $message->adRawDocumentId,
            ]);

            return;
        }

        if (AdRawDocumentStatus::PROCESSED === $afterProcessing->getStatus()) {
            $this->incrementProcessedAndTryFinalize($message->companyId, $marketplace, $reportDate);

            return;
        }

        // Action вернулся без исключения, но документ остался DRAFT — это
        // частичный успех (см. ProcessAdRawDocumentAction: markAsProcessed()
        // вызывается только когда !hasErrors). С точки зрения AdLoadJob'а это
        // failure: документ недообработан, считать как failed, чтобы условие
        // финализации (processed + failed == COUNT(raw)) обязательно сошлось.
        $this->logger->warning(
            'AdRawDocument остался в DRAFT после обработки — считаем как failure',
            [
                'companyId' => $message->companyId,
                'adRawDocumentId' => $message->adRawDocumentId,
                'status' => $afterProcessing->getStatus()->value,
            ],
        );

        $this->incrementFailedAndTryFinalize($message->companyId, $marketplace, $reportDate);
    }

    private function incrementProcessedAndTryFinalize(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $reportDate,
    ): void {
        $job = $this->adLoadJobRepository->findActiveJobCoveringDate($companyId, $marketplace, $reportDate);
        if (null === $job) {
            return;
        }

        $this->adLoadJobRepository->incrementProcessedDays($job->getId(), $companyId);
        $this->tryFinalizeJob($job->getId(), $companyId);
    }

    private function incrementFailedAndTryFinalize(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $reportDate,
    ): void {
        $job = $this->adLoadJobRepository->findActiveJobCoveringDate($companyId, $marketplace, $reportDate);
        if (null === $job) {
            return;
        }

        $this->adLoadJobRepository->incrementFailedDays($job->getId(), $companyId);
        $this->tryFinalizeJob($job->getId(), $companyId);
    }

    /**
     * Инкрементит failed_days без попытки финализации.
     *
     * Используется только в error-ветке (catch \Throwable): Messenger сделает
     * retry, и преждевременная финализация по overshoot'нутому счётчику
     * означала бы markFailed задания, которое могло бы завершиться successful
     * после последующих retries. Финализация остаётся за success/partial
     * ветками и за status-endpoint'ом (TODO commit 6).
     */
    private function incrementFailedOnly(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $reportDate,
    ): void {
        $job = $this->adLoadJobRepository->findActiveJobCoveringDate($companyId, $marketplace, $reportDate);
        if (null === $job) {
            return;
        }

        $this->adLoadJobRepository->incrementFailedDays($job->getId(), $companyId);
    }

    /**
     * Пробует финализировать job после инкремента processed/failed счётчика.
     *
     * Важно: после atomic increment (DBAL UPDATE минуя UoW) состояние entity
     * в Doctrine identity map устарело. Берём job через findFresh() — он
     * принудительно refresh'ит закэшированный instance либо читает заново.
     * Без этого на последнем документе `processed+failed` в памяти остался
     * бы ниже COUNT(raw), финализация бы пропустилась и job застрял бы в
     * RUNNING до следующего входящего сообщения (которого может и не быть).
     *
     * Условие финализации:
     *   chunksCompleted >= chunksTotal
     *   AND COUNT(AdRawDocument в диапазоне job'а) == processedDays + failedDays
     *
     * Race между воркерами покрывается SQL-guard'ами в markCompleted/markFailed
     * (`WHERE status IN ('pending','running')`) — второй вызов затронет 0 строк.
     *
     * TODO(commit 6): status-endpoint должен триггерить tryFinalizeJob, если
     * состояние финализации достигнуто, но job ещё RUNNING (консумер упал
     * между инкрементом и tryFinalizeJob).
     */
    private function tryFinalizeJob(string $jobId, string $companyId): void
    {
        $job = $this->adLoadJobRepository->findFresh($jobId);
        if (null === $job) {
            return;
        }

        if (AdLoadJobStatus::RUNNING !== $job->getStatus()) {
            return;
        }

        if ($job->getChunksCompleted() < $job->getChunksTotal()) {
            return;
        }

        $rawDocumentsCount = $this->rawDocumentRepository->countByCompanyMarketplaceAndDateRange(
            $companyId,
            $job->getMarketplace()->value,
            $job->getDateFrom(),
            $job->getDateTo(),
        );

        if ($job->getProcessedDays() + $job->getFailedDays() < $rawDocumentsCount) {
            return;
        }

        if (0 === $job->getFailedDays()) {
            $this->adLoadJobRepository->markCompleted($jobId, $companyId);
            $this->logger->info('AdLoadJob completed', [
                'jobId' => $jobId,
                'companyId' => $companyId,
                'processedDays' => $job->getProcessedDays(),
                'rawDocumentsCount' => $rawDocumentsCount,
            ]);

            return;
        }

        $reason = sprintf(
            'Partial failure: %d/%d documents failed processing',
            $job->getFailedDays(),
            $rawDocumentsCount,
        );
        $this->adLoadJobRepository->markFailed($jobId, $companyId, $reason);
        $this->logger->warning('AdLoadJob finalized with failures', [
            'jobId' => $jobId,
            'companyId' => $companyId,
            'failedDays' => $job->getFailedDays(),
            'processedDays' => $job->getProcessedDays(),
            'rawDocumentsCount' => $rawDocumentsCount,
        ]);
    }
}
