<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Entity\Document;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Repository\DocumentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;

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
     * @throws \DomainException сумма документа больше остатка транзакции ДДС, документ изменён или
     *                          удалён параллельно; транзакция откатывается
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
            $this->assertNotChangedConcurrently($document);

            $this->entityManager->flush();

            $days = [];
            if (DocumentStatus::ACTIVE === $initialStatus) {
                $day = $initialDate->setTime(0, 0);
                $days[$day->format('Y-m-d')] = $day;
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
     * Форма правила документ, загруженный до блокировки. Если с тех пор другой запрос закоммитил
     * правку того же документа (день, статус, тип, проект, ЦФО, операции — всё, из чего
     * строится регистр), применение формы поверх молча перезаписало бы её или пересчитало регистр по
     * устаревшим операциям: отказ, форму открывают заново. Загруженное состояние — исходные
     * данные UnitOfWork, сохранённое — строка документа под `FOR UPDATE` и его операции.
     */
    private function assertNotChangedConcurrently(Document $document): void
    {
        $stored = $this->documentRepository->lockStoredRegisterState(
            (string) $document->getId(),
            (string) $document->getCompany()->getId(),
        ) ?? throw new \DomainException('Документ ОПиУ не найден.');
        if ($stored['deleted']) {
            throw new \DomainException('Документ ОПиУ удалён.');
        }

        $unitOfWork = $this->entityManager->getUnitOfWork();
        $loaded = $unitOfWork->getOriginalEntityData($document);
        $loadedDate = $loaded['date'] ?? null;
        $loadedStatus = $loaded['status'] ?? null;
        $loadedType = $loaded['type'] ?? null;

        // Исходные данные UnitOfWork после вставки несут значения как есть (enum — строкой).
        $changed = !$loadedDate instanceof \DateTimeInterface
            || $loadedDate->format('Y-m-d H:i:s') !== (new \DateTimeImmutable($stored['date']))->format('Y-m-d H:i:s')
            || ($loadedStatus instanceof \BackedEnum ? (string) $loadedStatus->value : $loadedStatus) !== $stored['status']
            || ($loadedType instanceof \BackedEnum ? (string) $loadedType->value : $loadedType) !== $stored['type']
            || $this->entityId($loaded['projectDirection'] ?? null) !== $stored['projectDirectionId']
            || ($loaded['responsibilityCenterId'] ?? null) !== $stored['responsibilityCenterId'];

        $operations = $document->getOperations();
        $loadedOperations = $operations instanceof PersistentCollection ? $operations->getSnapshot() : [];
        $loadedById = [];
        foreach ($loadedOperations as $operation) {
            $loadedById[(string) $operation->getId()] = $unitOfWork->getOriginalEntityData($operation);
        }

        $loadedIds = array_keys($loadedById);
        $storedIds = array_keys($stored['operations']);
        sort($loadedIds);
        sort($storedIds);
        if ($loadedIds !== $storedIds) {
            $changed = true;
        }

        foreach ($stored['operations'] as $id => $storedOperation) {
            $loadedOperation = $loadedById[$id] ?? null;
            if (null === $loadedOperation) {
                continue;
            }

            $loadedAmount = $loadedOperation['amount'] ?? null;
            if (!is_numeric($loadedAmount)
                || 0 !== bccomp((string) $loadedAmount, $storedOperation['amount'], 2)
                || $this->entityId($loadedOperation['category'] ?? null) !== $storedOperation['categoryId']
                || $this->entityId($loadedOperation['projectDirection'] ?? null) !== $storedOperation['projectDirectionId']
                || ($loadedOperation['responsibilityCenterId'] ?? null) !== $storedOperation['responsibilityCenterId']
            ) {
                $changed = true;
            }
        }

        if ($changed) {
            throw new \DomainException('Документ изменён другим пользователем — откройте его заново.');
        }
    }

    private function entityId(mixed $entity): ?string
    {
        if (null === $entity) {
            return null;
        }

        $id = \is_object($entity) && method_exists($entity, 'getId') ? $entity->getId() : null;

        return null !== $id ? (string) $id : null;
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
