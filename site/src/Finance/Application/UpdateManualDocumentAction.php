<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Entity\Document;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Repository\DocumentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Правка документа ОПиУ из формы: документ уже изменён формой и не сохранён. Проверка остатка
 * транзакции ДДС, распределённая сумма, документ и регистр прежнего и нового дня — один commit
 * под блокировкой строки транзакции: параллельный документ на тот же остаток ждёт его.
 */
final readonly class UpdateManualDocumentAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PLRegisterUpdater $plRegisterUpdater,
        private DocumentRepository $documentRepository,
    ) {
    }

    /**
     * @param DocumentStatus $initialStatus статус до применения формы
     * @param \DateTimeImmutable $initialDate дата до применения формы
     *
     * @throws \DomainException сумма документа больше остатка транзакции ДДС или документ удалён
     *                          параллельно; транзакция откатывается
     */
    public function __invoke(Document $document, DocumentStatus $initialStatus, \DateTimeImmutable $initialDate): void
    {
        $this->entityManager->wrapInTransaction(function () use ($document, $initialStatus, $initialDate): void {
            $cashTransaction = $document->getCashTransaction();
            if (null !== $cashTransaction) {
                $this->entityManager->lock($cashTransaction, LockMode::PESSIMISTIC_WRITE);
                // Остаток — по свежей коллекции документов, а не по загруженной до блокировки.
                // Сам документ не перечитывается: в нём несохранённые правки формы.
                $this->entityManager->refresh($cashTransaction);
                $this->refreshSiblingDocuments($cashTransaction->getDocuments(), $document);
                $cashTransaction->assertCanAllocateAmount($document->getTotalAmount(), $document);
                $cashTransaction->recalculateAllocatedAmount();
            }

            // Строка документа — после строки транзакции ДДС (источник раньше производных).
            // Сохранённый день читается под блокировкой: параллельная правка могла перенести
            // документ после загрузки формы, и её день тоже освобождается.
            $stored = $this->documentRepository->lockStoredDateAndStatus(
                (string) $document->getId(),
                (string) $document->getCompany()->getId(),
            ) ?? throw new \DomainException('Документ ОПиУ не найден.');
            if ($stored['deleted']) {
                throw new \DomainException('Документ ОПиУ удалён.');
            }

            $this->entityManager->flush();

            $days = [];
            foreach ([[$initialStatus, $initialDate], [$stored['status'], $stored['date']]] as [$status, $date]) {
                if (DocumentStatus::ACTIVE === $status) {
                    $day = $date->setTime(0, 0);
                    $days[$day->format('Y-m-d')] = $day;
                }
            }
            if (DocumentStatus::ACTIVE === $document->getStatus()) {
                $day = $document->getDate()->setTime(0, 0);
                $days[$day->format('Y-m-d')] = $day;
            }

            foreach ($days as $day) {
                $this->plRegisterUpdater->recalcRange($document->getCompany(), $day, $day);
            }
        });

        $this->plRegisterUpdater->invalidateCacheForCompanyId((string) $document->getCompany()->getId());
    }

    /**
     * Перечитанная коллекция транзакции гидрирует уже загруженные (до блокировки) документы и
     * операции из identity map, со старыми суммами: соседние документы и их операции
     * перечитываются по одному, иначе параллельная правка соседа не видна ни проверке остатка,
     * ни пересчёту регистра.
     *
     * @param iterable<Document> $documents
     */
    private function refreshSiblingDocuments(iterable $documents, Document $edited): void
    {
        foreach ($documents as $sibling) {
            if ($sibling === $edited) {
                continue;
            }

            $this->entityManager->refresh($sibling);
            foreach ($sibling->getOperations() as $operation) {
                $this->entityManager->refresh($operation);
            }
        }
    }
}
