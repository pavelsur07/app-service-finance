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
 *  - Идемпотентен: если документ уже терминален (PROCESSED/FAILED) — молча
 *    возвращается. На retry Messenger'а ProcessAdRawDocumentAction также ловит
 *    non-DRAFT и бросает AdRawDocumentAlreadyProcessedException, которое мы
 *    обрабатываем как skip.
 *  - На ошибке / частичном успехе помечает документ FAILED с причиной через
 *    атомарный SQL и триггерит финализацию AdLoadJob. Рекламный retry
 *    инкрементов не даёт: повторный заход в Action увидит status=FAILED и
 *    сразу short-circuit'ит, без двойного учёта.
 *
 * Финализация AdLoadJob ({@see self::tryFinalizeJob}) — condition:
 *   chunksCompleted >= chunksTotal
 *   AND COUNT(total AdRawDocument в [dateFrom; dateTo]) == COUNT(PROCESSED) + COUNT(FAILED)
 *
 * loadedDays в условие не входит: он coverage-based (см. FetchOzonAdStatisticsHandler)
 * и может overshoot'ить при retry оркестратора. COUNT по AdRawDocument
 * идемпотентен благодаря UniqueConstraint(company_id, marketplace, report_date)
 * и финальному status (PROCESSED или FAILED, оба терминальны).
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
            $this->logger->info('AdRawDocument уже терминален, повторный запуск пропущен', [
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
            // вызовом Action (или документ удалили). Не помечаем FAILED и не
            // финализируем — это сделает воркер, который реально обработал.
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
            // Помечаем документ FAILED с причиной и финализируем job. Финализация
            // безопасна: `markFailedWithReason` идемпотентен (WHERE status =
            // 'draft'), повторный заход из retry сразу short-circuit'ит в Action
            // через AdRawDocumentAlreadyProcessedException. Rethrow нужен, чтобы
            // Messenger видел падение — метрики/мониторинг/failed-queue при
            // исчерпании retries.
            $this->logger->error(
                'Ошибка обработки AdRawDocument',
                $e,
                [
                    'companyId' => $message->companyId,
                    'adRawDocumentId' => $message->adRawDocumentId,
                ],
            );

            $this->markDocumentFailedAndTryFinalize(
                $message->companyId,
                $message->adRawDocumentId,
                $marketplace,
                $reportDate,
                $this->truncateReason($e->getMessage()),
            );

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
            $this->tryFinalizeJob($message->companyId, $marketplace, $reportDate);

            return;
        }

        // Action вернулся без исключения, но документ остался DRAFT — это
        // частичный успех (см. ProcessAdRawDocumentAction: markAsProcessed()
        // вызывается только когда !hasErrors). С точки зрения AdLoadJob'а это
        // failure: документ не должен оставаться в очереди обработки, считаем
        // его терминальным через markFailedWithReason.
        $this->logger->warning(
            'AdRawDocument остался в DRAFT после обработки — помечаем как FAILED',
            [
                'companyId' => $message->companyId,
                'adRawDocumentId' => $message->adRawDocumentId,
                'status' => $afterProcessing->getStatus()->value,
            ],
        );

        $this->markDocumentFailedAndTryFinalize(
            $message->companyId,
            $message->adRawDocumentId,
            $marketplace,
            $reportDate,
            'Partial processing: document remained in DRAFT after Action',
        );
    }

    private function markDocumentFailedAndTryFinalize(
        string $companyId,
        string $adRawDocumentId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $reportDate,
        string $reason,
    ): void {
        // Идемпотентный SQL UPDATE (WHERE status='draft'): если параллельный
        // воркер успел обработать документ как PROCESSED, 0 строк затронуто —
        // финализацию всё равно попробуем, т.к. состояние в БД могло стать
        // terminal независимо от текущей попытки.
        $this->rawDocumentRepository->markFailedWithReason($adRawDocumentId, $companyId, $reason);
        $this->tryFinalizeJob($companyId, $marketplace, $reportDate);
    }

    /**
     * Пробует финализировать job по дате текущего документа.
     *
     * Условие финализации:
     *   chunksCompleted >= chunksTotal
     *   AND COUNT(AdRawDocument в диапазоне job'а) == COUNT(PROCESSED) + COUNT(FAILED)
     *
     * Important: после атомарного UPDATE статуса документа (минуя UoW)
     * состояние job'а в Doctrine identity map может устареть — берём job через
     * findFresh(), который принудительно refresh'ит закэшированный instance
     * либо читает заново. Без этого на последнем документе chunksCompleted в
     * памяти мог бы остаться ниже chunksTotal, и финализация пропустилась бы.
     *
     * Race между воркерами покрывается SQL-guard'ами в markCompleted/markFailed
     * (`WHERE status IN ('pending','running')`) — второй вызов затронет 0 строк.
     */
    private function tryFinalizeJob(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $reportDate,
    ): void {
        $job = $this->adLoadJobRepository->findActiveJobCoveringDate($companyId, $marketplace, $reportDate);
        if (null === $job) {
            return;
        }

        $fresh = $this->adLoadJobRepository->findFresh($job->getId());
        if (null === $fresh) {
            return;
        }

        if (AdLoadJobStatus::RUNNING !== $fresh->getStatus()) {
            return;
        }

        if ($fresh->getChunksCompleted() < $fresh->getChunksTotal()) {
            return;
        }

        $total = $this->rawDocumentRepository->countByCompanyMarketplaceAndDateRange(
            $companyId,
            $fresh->getMarketplace()->value,
            $fresh->getDateFrom(),
            $fresh->getDateTo(),
        );

        $terminal = $this->rawDocumentRepository->countTerminalByCompanyMarketplaceAndDateRange(
            $companyId,
            $fresh->getMarketplace()->value,
            $fresh->getDateFrom(),
            $fresh->getDateTo(),
        );

        $processed = $terminal['processed'];
        $failed = $terminal['failed'];

        if ($processed + $failed < $total) {
            return;
        }

        if (0 === $failed) {
            $this->adLoadJobRepository->markCompleted($fresh->getId(), $companyId);
            $this->logger->info('AdLoadJob completed', [
                'jobId' => $fresh->getId(),
                'companyId' => $companyId,
                'processedDocs' => $processed,
                'totalDocs' => $total,
            ]);

            return;
        }

        $reason = sprintf(
            'Partial failure: %d/%d documents failed processing',
            $failed,
            $total,
        );
        $this->adLoadJobRepository->markFailed($fresh->getId(), $companyId, $reason);
        $this->logger->warning('AdLoadJob finalized with failures', [
            'jobId' => $fresh->getId(),
            'companyId' => $companyId,
            'failedDocs' => $failed,
            'processedDocs' => $processed,
            'totalDocs' => $total,
        ]);
    }

    /**
     * Обрезает причину до безопасной длины для TEXT-колонки БД и логов.
     * PostgreSQL TEXT без лимита, но массивный trace/stack в поле ошибки —
     * это нагрузка на репликации и UI. Сообщение достаточно идентифицирует
     * причину, trace остаётся в логе через $this->logger->error.
     */
    private function truncateReason(string $reason): string
    {
        $max = 2000;
        if ('' === $reason) {
            return 'Unknown failure';
        }

        return mb_strlen($reason) > $max ? mb_substr($reason, 0, $max) : $reason;
    }
}
