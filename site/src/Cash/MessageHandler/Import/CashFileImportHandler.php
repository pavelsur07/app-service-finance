<?php

declare(strict_types=1);

namespace App\Cash\MessageHandler\Import;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Infrastructure\Lock\CashImportAccountLock;
use App\Cash\Message\Import\CashFileImportMessage;
use App\Cash\Service\Import\File\CashFileImportService;
use App\Cash\Service\Import\ImportLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Фазы: захват задания (queued → processing, свой commit) → импорт пачками → финализация.
 * Задания одного счёта не идут параллельно: CashImportAccountLock берётся до захвата и
 * держится до финализации. Занято — задание не трогается, сообщение повторяется через
 * RETRY_DELAY_MS. Задание в processing при свободной блокировке брошено умершим воркером
 * (сессионная блокировка снялась вместе с его соединением) — импорт продолжается: дедуп по
 * количеству дописывает только недостающее.
 */
#[AsMessageHandler]
final class CashFileImportHandler
{
    private const RETRY_DELAY_MS = 60_000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashFileImportService $importService,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ImportLogger $importLogger,
        private readonly LoggerInterface $logger,
        private readonly CashImportAccountLock $accountLock,
    ) {
    }

    public function __invoke(CashFileImportMessage $message): void
    {
        $job = $this->entityManager->find(CashFileImportJob::class, $message->getJobId());
        if (!$job instanceof CashFileImportJob) {
            return;
        }

        if (!\in_array($job->getStatus(), [CashFileImportJob::STATUS_QUEUED, CashFileImportJob::STATUS_PROCESSING], true)) {
            $this->logSkipped($message, $job);

            return;
        }

        $companyId = (string) $job->getCompany()->getId();
        $accountId = (string) $job->getMoneyAccount()->getId();

        if (!$this->accountLock->tryAcquire($companyId, $accountId)) {
            $this->logger->warning('Cash file import deferred: another import of the account is running', [
                'jobId' => $message->getJobId(),
                'companyId' => $companyId,
            ]);

            throw new RecoverableMessageHandlingException('Импорт в этот счёт уже выполняется.', 0, null, self::RETRY_DELAY_MS);
        }

        try {
            $this->process($message);
        } finally {
            try {
                $this->accountLock->release($companyId, $accountId);
            } catch (\Throwable $exception) {
                // Соединение потеряно — PostgreSQL снял сессионную блокировку сам.
                $this->logger->warning('Cash file import: account lock release failed', [
                    'jobId' => $message->getJobId(),
                    'companyId' => $companyId,
                    'exceptionClass' => $exception::class,
                ]);
            }
        }
    }

    private function process(CashFileImportMessage $message): void
    {
        $resumed = false;
        $this->entityManager->beginTransaction();

        try {
            $job = $this->entityManager->find(
                CashFileImportJob::class,
                $message->getJobId(),
                LockMode::PESSIMISTIC_WRITE
            );
            if (!$job instanceof CashFileImportJob) {
                $this->entityManager->commit();

                return;
            }
            // Сущность загружена до блокировки счёта: статус мог смениться, пока ждали.
            $this->entityManager->refresh($job);

            if (CashFileImportJob::STATUS_QUEUED === $job->getStatus()) {
                $job->start();
                $this->entityManager->flush();
            } elseif (CashFileImportJob::STATUS_PROCESSING === $job->getStatus()) {
                $resumed = true;
            } else {
                $this->entityManager->commit();
                $this->logSkipped($message, $job);

                return;
            }

            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        $companyId = (string) $job->getCompany()->getId();
        $logContext = ['jobId' => $message->getJobId(), 'companyId' => $companyId];
        if ($resumed) {
            $this->logger->warning('Cash file import resumed: job was abandoned in processing', $logContext);
        } else {
            $this->logger->info('Cash file import started', $logContext);
        }

        $importException = null;

        try {
            $this->importService->import($job);
            if ($resumed) {
                // Прежний воркер мог умереть после последней пачки, но до пересчёта остатков:
                // тогда импорт выше ничего не создаёт и пересчёт пропускает. Добираем диапазон
                // всех строк задания с первого старта.
                $this->importService->recalculateCommittedRows($job);
            }
        } catch (\Throwable $exception) {
            $importException = $exception;
        }

        // Ошибка БД внутри импорта закрывает EM: без сброса fail() не сохранить,
        // сообщение уходило в retry, а retry пропускал задачу в processing навсегда.
        $entityManager = $this->entityManager;
        if (!$entityManager->isOpen()) {
            $this->managerRegistry->resetManager();
            $resetManager = $this->managerRegistry->getManager();
            \assert($resetManager instanceof EntityManagerInterface);
            $entityManager = $resetManager;
        }

        $freshJob = $entityManager->find(CashFileImportJob::class, $message->getJobId());
        if (!$freshJob instanceof CashFileImportJob) {
            return;
        }

        if (null === $importException) {
            $freshJob->finishOk();
            $freshJob->setErrorMessage(null);
            $entityManager->flush();
            $this->logger->info('Cash file import finished', $logContext + ['status' => $freshJob->getStatus()]);

            return;
        }

        // Текст исключения в лог не идёт: у DBAL в нём SQL с данными строк выписки.
        // Невалидный вход (формат файла, чужой счёт) — warning, остальное — инцидент.
        $level = $importException instanceof \DomainException || $importException instanceof \InvalidArgumentException
            ? LogLevel::WARNING
            : LogLevel::ERROR;
        $this->logger->log($level, 'Cash file import failed', $logContext + [
            'exceptionClass' => $importException::class,
            'exceptionAt' => sprintf('%s:%d', $importException->getFile(), $importException->getLine()),
        ]);

        $message = sprintf(
            '[%s] %s at %s:%d',
            $importException::class,
            $importException->getMessage(),
            $importException->getFile(),
            $importException->getLine()
        );
        $message = mb_substr($message, 0, 2000);

        $freshJob->fail($message);
        $importLog = $freshJob->getImportLog();
        if (null !== $importLog && null === $importLog->getFinishedAt()) {
            $this->importLogger->finish($importLog);
        }
        $entityManager->flush();

        // После фиксации failed: сбой пересчёта не должен вернуть задачу в processing.
        try {
            $this->importService->recalculateCommittedRows($freshJob);
        } catch (\Throwable $recalcException) {
            $this->logger->error('Cash file import: balance recalculation after failure failed', $logContext + [
                'exceptionClass' => $recalcException::class,
            ]);
        }
    }

    private function logSkipped(CashFileImportMessage $message, CashFileImportJob $job): void
    {
        // Повторная доставка завершённой задачи — ничего не делаем.
        $this->logger->warning('Cash file import skipped: job is already finished', [
            'jobId' => $message->getJobId(),
            'status' => $job->getStatus(),
        ]);
    }
}
