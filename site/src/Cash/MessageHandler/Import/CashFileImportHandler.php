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
        // --- ШАГ 1: Взятие в работу (Транзакция) ---
        $this->entityManager->beginTransaction();

        try {
            // Блокируем строку для записи, чтобы другие воркеры не взяли её
            $job = $this->entityManager->find(
                CashFileImportJob::class,
                $message->getJobId(),
                LockMode::PESSIMISTIC_WRITE
            );

            // Если задачи нет, просто выходим
            if (!$job instanceof CashFileImportJob) {
                $this->entityManager->rollback();
                return;
            }

            // Если статус уже не QUEUED (кто-то другой взял), выходим
            if (CashFileImportJob::STATUS_QUEUED !== $job->getStatus()) {
                $this->entityManager->commit();
                return;
            }

            // Ставим статус "В работе"
            $job->start();
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            // Если что-то пошло не так на старте - откатываем
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            throw $exception;
        }

        // --- ШАГ 2: Выполнение импорта ---
        $importException = null;

        try {
            // Запускаем сервис (он сам управляет своими батчами и памятью)
            $this->importService->import($job);
        } catch (\Throwable $exception) {
            $importException = $exception;
        }

        // --- ШАГ 3: Финализация (Сохранение результата) ---

        // Критическая проверка: если EntityManager закрылся (например, из-за ошибки SQL внутри сервиса),
        // мы не сможем сохранить статус через ORM.
        if (!$this->entityManager->isOpen()) {
            // Здесь можно пересоздать менеджер через ManagerRegistry, если очень нужно,
            // но для простоты просто выбрасываем исключение, чтобы Messenger увидел проблему.
            throw new \RuntimeException(
                'Entity Manager closed during import. Job ID: ' . $message->getJobId(),
                0,
                $importException
            );
        }

        // Очищаем память Doctrine, чтобы подтянуть свежее состояние Job из базы
        // Это важно, если сервис импорта делал $em->clear()
        $this->entityManager->clear();

        $freshJob = $this->entityManager->find(CashFileImportJob::class, $message->getJobId());

        if (!$freshJob instanceof CashFileImportJob) {
            return;
        }

        if (null === $importException) {
            // Успех
            $freshJob->finishOk();
            // Очищаем поле ошибки, если там были старые записи
            $freshJob->setErrorMessage(null);
        } else {
            // Ошибка
            $errorMsg = sprintf(
                'Error [%s]: %s at %s:%d',
                $importException::class,
                $importException->getMessage(),
                $importException->getFile(),
                $importException->getLine()
            );

            // Обрезаем сообщение до 2000 символов (или сколько у вас в базе)
            $freshJob->fail(mb_substr($errorMsg, 0, 2000));
        }

        // Финальное сохранение статуса
        $this->entityManager->flush();
    }
}
