<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\MessageHandler;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\ProcessOzonRealizationMessage;
use App\Marketplace\Ozon\Application\Action\ProcessOzonRealizationAction;
use App\Marketplace\Ozon\Application\Action\RunOzonReconciliationAction;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationReport;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Автообработка отчёта «Реализация»: тот же `ProcessOzonRealizationAction`, что и кнопка «Применить выручку»,
 * затем пересчёт сверки Ozon за месяц.
 *
 * - Месяц с окончательно закрытым этапом «Продажи/возвраты» не обрабатывается: статус `conflict` и `warning`
 *   (оперативное, то есть предварительное, закрытие автообработку не блокирует — оно пересобирается).
 * - Идемпотентно: пара в `success` пропускается, повторная доставка ничего не пересоздаёт.
 * - Сбой обработки → `failed` с повтором через час (повтор ведёт часовой опрос); инцидент виден как `error`.
 * - Сбой пересчёта сверки на успех обработки не влияет: ночной гейт сверки её досчитает.
 */
#[AsMessageHandler]
final class ProcessOzonRealizationHandler
{
    private const LOCK_TTL_SECONDS = 900;
    private const RETRY = 'PT1H';
    /** Сколько загрузок/обработок подряд допускаем до терминального сбоя: неустранимая ошибка иначе повторялась бы весь период. */
    private const MAX_ATTEMPTS = 4;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly MarketplaceMonthCloseRepository $monthCloseRepository,
        private readonly MarketplaceFinancialReportSyncStatusRepository $statusRepository,
        private readonly ProcessOzonRealizationAction $processAction,
        private readonly RunOzonReconciliationAction $reconciliationAction,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessOzonRealizationMessage $message): void
    {
        $lock = $this->lockFactory->createLock(
            sprintf('ozon_realization_process_%s_%d_%02d', $message->companyId, $message->year, $message->month),
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            $this->logger->warning('Ozon realization processing already in progress, skipping', ['company_id' => $message->companyId, 'year' => $message->year, 'month' => $message->month]);

            return;
        }

        try {
            $this->process($message);
        } finally {
            $lock->release();
        }
    }

    private function process(ProcessOzonRealizationMessage $message): void
    {
        $context = ['company_id' => $message->companyId, 'raw_doc_id' => $message->rawDocumentId, 'year' => $message->year, 'month' => $message->month];

        $document = $this->rawDocumentRepository->find($message->rawDocumentId);
        if (!$document instanceof MarketplaceRawDocument || (string) $document->getCompany()->getId() !== $message->companyId || 'realization' !== $document->getDocumentType() || MarketplaceType::OZON !== $document->getMarketplace()) {
            $this->logger->error('Ozon realization processing: document not found or does not belong to the company', $context);
            // Без этого пара висела бы в raw_loaded/processing, и опрос ставил бы ту же обработку каждый час.
            $this->failPairFinally($message);

            return;
        }

        $status = $this->statusRepository->findOrCreateForDay(
            $message->connectionId,
            $message->companyId,
            MarketplaceType::OZON,
            OzonRealizationReport::REPORT_TYPE,
            OzonRealizationReport::apiEndpoint(),
            OzonRealizationReport::businessDate($message->year, $message->month),
        );

        if (FinancialReportSyncStatus::SUCCESS === $status->getStatus()) {
            $this->logger->info('Ozon realization already processed, skipping', $context);

            return;
        }

        $monthClose = $this->monthCloseRepository->findByPeriod($message->companyId, MarketplaceType::OZON, $message->year, $message->month);
        if (null !== $monthClose && $monthClose->isStageClosed(CloseStage::SALES_RETURNS) && !$monthClose->isStageLastCloseWasPreliminary(CloseStage::SALES_RETURNS)) {
            $status->markConflict('MonthStageClosed', 'Этап «Продажи и возвраты» месяца закрыт: «Реализация» загружена, но не применена.', null, null);
            $this->saveAndFlush($status);
            $this->logger->warning('Ozon realization not applied: sales/returns stage is closed', $context);

            return;
        }

        $startHash = $status->getRowsHash();
        $status->markProcessing();
        $this->saveAndFlush($status);
        $this->logger->info('Ozon realization processing started', $context);

        try {
            $result = ($this->processAction)($message->companyId, $message->rawDocumentId);
        } catch (\Throwable $e) {
            $this->logger->error('Ozon realization processing failed', $context + ['error_class' => $e::class, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $this->recordFailure($message, $e);

            return;
        }

        // Action сбрасывает EntityManager каждые 250 строк, и `$status` после него отсоединён: его нельзя ни сохранять
        // (persist вставил бы дубликат пары), ни помечать успехом. В отчёте поменьше 250 строк сброса нет, и в памяти остался
        // устаревший экземпляр — загрузчик мог за это время сменить хеш в БД. Поэтому в обоих случаях сбрасываем менеджер и
        // читаем состояние пары из базы заново.
        if ($this->em->isOpen()) {
            $this->em->clear();
        }
        $status = $this->freshStatus($message);
        if (null === $status) {
            $this->logger->error('Ozon realization processing: status row disappeared', $context);

            return;
        }

        if ($status->getRowsHash() !== $startHash) {
            // Пока шла обработка, загрузчик положил новую версию отчёта: успех относился бы к старой. Новую обработку он уже поставил.
            $this->logger->info('Ozon realization changed during processing, newer version will be processed', $context);

            return;
        }

        try {
            $status->markSuccess();
            $this->saveAndFlush($status);
        } catch (\Throwable $e) {
            $this->logger->error('Ozon realization processed but the status could not be saved', $context + ['error_class' => $e::class]);
            $this->recordFailure($message, $e);

            return;
        }

        $this->logger->info('Ozon realization processed', $context + ['created' => $result['created'], 'updated' => $result['updated'], 'skipped' => $result['skipped']]);

        $this->refreshReconciliation($message, $context);
    }

    private function freshStatus(ProcessOzonRealizationMessage $message): ?MarketplaceFinancialReportSyncStatus
    {
        if (!$this->em->isOpen()) {
            return null;
        }

        return $this->statusRepository->findByBusinessDay(
            $message->companyId,
            MarketplaceType::OZON,
            OzonRealizationReport::REPORT_TYPE,
            OzonRealizationReport::businessDate($message->year, $message->month),
        );
    }

    private function failPairFinally(ProcessOzonRealizationMessage $message): void
    {
        $status = $this->freshStatus($message);
        if (null === $status) {
            return;
        }

        $status->markFailedFinal('DocumentMismatch', 'Документ отчёта не найден или принадлежит другой компании.', null, null);
        $this->saveAndFlush($status);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function refreshReconciliation(ProcessOzonRealizationMessage $message, array $context): void
    {
        try {
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $message->year, $message->month));
            ($this->reconciliationAction)($message->companyId, $from, $from->modify('last day of this month'));
        } catch (\Throwable $e) {
            $this->logger->warning('Ozon reconciliation refresh after realization failed', $context + ['error_class' => $e::class]);
        }
    }

    private function saveAndFlush(MarketplaceFinancialReportSyncStatus $status): void
    {
        $this->statusRepository->save($status);
        $this->em->flush();
    }

    /**
     * После сбоя обработки EntityManager может держать незаписанные строки реализации, а статус — устаревшее состояние:
     * сбрасываем менеджер, перечитываем статус и фиксируем сбой с повтором. Ошибка БД закрывает EntityManager
     * (так падает и вставка с переполнением числа): тогда статус пишется напрямую через соединение, иначе пара
     * осталась бы в `processing` до «залипания» и повторялась бы с задержкой.
     */
    private function recordFailure(ProcessOzonRealizationMessage $message, \Throwable $e): void
    {
        if (!$this->em->isOpen()) {
            $this->recordFailureWithoutEntityManager($message, $e);

            return;
        }

        $this->em->clear();
        $status = $this->freshStatus($message);
        if (null === $status) {
            return;
        }

        if ($status->getAttempts() >= self::MAX_ATTEMPTS) {
            $status->markFailedFinal($e::class, mb_substr($e->getMessage(), 0, 300), null, null);
        } else {
            $status->markFailedRetryable($e::class, mb_substr($e->getMessage(), 0, 300), null, null, $this->clock->now()->add(new \DateInterval(self::RETRY)));
        }
        $this->saveAndFlush($status);
    }

    private function recordFailureWithoutEntityManager(ProcessOzonRealizationMessage $message, \Throwable $e): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                'UPDATE marketplace_financial_report_sync_statuses
                 SET status = CASE WHEN attempts >= :maxAttempts THEN :finalStatus ELSE :status END,
                     last_error_class = :errorClass, last_error_message = :errorMessage,
                     next_retry_at = CASE WHEN attempts >= :maxAttempts THEN NULL ELSE CAST(:nextRetryAt AS timestamp) END,
                     finished_at = NULL, updated_at = :now
                 WHERE company_id = :companyId AND marketplace = :marketplace AND report_type = :reportType AND business_date = :businessDate',
                [
                    'status' => FinancialReportSyncStatus::FAILED->value,
                    'finalStatus' => FinancialReportSyncStatus::FAILED_FINAL->value,
                    'maxAttempts' => self::MAX_ATTEMPTS,
                    'errorClass' => $e::class,
                    'errorMessage' => mb_substr($e->getMessage(), 0, 300),
                    'nextRetryAt' => $this->clock->now()->add(new \DateInterval(self::RETRY))->format('Y-m-d H:i:s'),
                    'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                    'companyId' => $message->companyId,
                    'marketplace' => MarketplaceType::OZON->value,
                    'reportType' => OzonRealizationReport::REPORT_TYPE,
                    'businessDate' => OzonRealizationReport::businessDate($message->year, $message->month)->format('Y-m-d'),
                ],
            );
        } catch (\Throwable $inner) {
            $this->logger->error('Ozon realization: could not record the failure', ['company_id' => $message->companyId, 'error_class' => $inner::class]);
        }
    }
}
