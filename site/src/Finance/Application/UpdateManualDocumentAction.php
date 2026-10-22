<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Entity\Document;
use App\Finance\Enum\DocumentStatus;
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
    ) {
    }

    /**
     * @param DocumentStatus     $initialStatus статус до применения формы
     * @param \DateTimeImmutable $initialDate   дата до применения формы
     *
     * @throws \DomainException сумма документа больше остатка транзакции ДДС; транзакция откатывается
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
                $cashTransaction->assertCanAllocateAmount($document->getTotalAmount(), $document);
                $cashTransaction->recalculateAllocatedAmount();
            }

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
}
