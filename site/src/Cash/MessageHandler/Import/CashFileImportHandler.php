<?php

namespace App\Cash\MessageHandler\Import;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Message\Import\CashFileImportMessage;
use App\Cash\Service\Import\File\CashFileImportService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CashFileImportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashFileImportService $importService,
    ) {
    }

    public function __invoke(CashFileImportMessage $message): void
    {
        $jobId = $message->getJobId();

        // 1. ПОПЫТКА ВЗЯТЬ ЗАДАЧУ (Блокировка и старт)
        if (!$this->tryStartJob($jobId)) {
            // Если не удалось взять (занята или не найдена) - просто уходим
            return;
        }

        $error = null;

        // 2. ВЫПОЛНЕНИЕ (Делегируем всю работу сервису)
        try {
            // Передаем ID, а не объект, чтобы сервис загрузил свежую копию сам
            $this->importService->import($jobId);
        } catch (\Throwable $e) {
            $error = $e;
        }

        // 3. ФИНАЛИЗАЦИЯ (Сохранение итога)
        // Мы делаем это в отдельном методе, который умеет "воскрешать" EntityManager
        $this->finishJob($jobId, $error);
    }

    /**
     * Пытается перевести задачу в статус "В работе".
     * Возвращает true, если успешно начали.
     */
    private function tryStartJob(string $jobId): bool
    {
        $this->entityManager->beginTransaction();
        try {
            // PESSIMISTIC_WRITE блокирует строку в БД, чтобы другие воркеры ждали
            $job = $this->entityManager->find(CashFileImportJob::class, $jobId, LockMode::PESSIMISTIC_WRITE);

            if (!$job instanceof CashFileImportJob) {
                $this->entityManager->rollback();
                return false;
            }

            if (CashFileImportJob::STATUS_QUEUED !== $job->getStatus()) {
                $this->entityManager->commit();
                return false;
            }

            $job->start();
            $this->entityManager->flush();
            $this->entityManager->commit();

            return true;
        } catch (\Throwable $e) {
            // Если транзакция упала - откатываем
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            // Можно залогировать ошибку старта, если нужно
            return false;
        }
    }

    /**
     * Гарантированно сохраняет финальный статус задачи.
     */
    private function finishJob(string $jobId, ?\Throwable $exception): void
    {
        // Если EntityManager "умер" во время импорта (ошибка SQL), его нельзя использовать.
        // Проверяем и если закрыт - выбрасываем исключение (Messenger его поймает и перезапустит воркер, если надо)
        if (!$this->entityManager->isOpen()) {
            // В Symfony Messenger это заставит воркер корректно умереть и перезапуститься
            throw new \RuntimeException('EntityManager closed during import. Job status might not be saved.', 0, $exception);
        }

        // Очищаем IdentityMap. Это критически важно после пакетной обработки (batch processing),
        // чтобы Doctrine забыла старые ссылки на сущности и загрузила Job заново.
        $this->entityManager->clear();

        $this->entityManager->beginTransaction();
        try {
            // Снова блокируем, чтобы безопасно обновить статус
            $job = $this->entityManager->find(CashFileImportJob::class, $jobId, LockMode::PESSIMISTIC_WRITE);

            if (!$job instanceof CashFileImportJob) {
                $this->entityManager->rollback();
                return;
            }

            $now = new \DateTimeImmutable();

            if (null === $exception) {
                $job->finishOk();
                $job->setErrorMessage(null);
            } else {
                $errorMsg = sprintf(
                    'Error: %s [%s] at %s:%d',
                    $exception->getMessage(),
                    $exception::class,
                    basename($exception->getFile()),
                    $exception->getLine()
                );
                $job->fail(mb_substr($errorMsg, 0, 2000));
            }

            // Принудительно ставим дату завершения (страховка)
            if (method_exists($job, 'setFinishedAt')) {
                $job->setFinishedAt($now);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            // Тут уже ничего не поделаешь, просто логируем в поток вывода
            file_put_contents('php://stderr', "CRITICAL: Failed to save job status: " . $e->getMessage());
        }
    }
}
