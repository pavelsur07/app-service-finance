<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Entity\Document;
use App\Finance\Repository\DocumentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SoftDeleteDocumentAction
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private EntityManagerInterface $entityManager,
        private PLRegisterUpdater $plRegisterUpdater,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $documentId,
        ?string $actorUserId = null,
        ?string $reason = null,
    ): void {
        // Документ, распределённая сумма транзакции ДДС и регистр — один commit: повтор после сбоя
        // не застаёт удалённый документ с непересчитанным регистром.
        $this->entityManager->wrapInTransaction(function () use ($companyId, $documentId, $actorUserId, $reason): void {
            $document = $this->documentRepository->findByIdAndCompany($documentId, $companyId)
                ?? throw new \DomainException('Документ ОПиУ не найден.');

            if ($document->isDeleted()) {
                return;
            }

            $this->lockCashTransaction($document);

            $document->markDeleted($actorUserId, $reason);
            $document->getCashTransaction()?->recalculateAllocatedAmount();

            $this->entityManager->flush();

            $day = $document->getDate()->setTime(0, 0);
            $this->plRegisterUpdater->recalcRange($document->getCompany(), $day, $day);
        });

        $this->plRegisterUpdater->invalidateCacheForCompanyId($companyId);
    }

    /**
     * Строка транзакции ДДС — под FOR UPDATE и перечитана: распределённая сумма считается по
     * свежей коллекции документов, а не по загруженной до параллельного создания документа.
     */
    private function lockCashTransaction(Document $document): void
    {
        $cashTransaction = $document->getCashTransaction();
        if (null === $cashTransaction) {
            return;
        }

        $this->entityManager->lock($cashTransaction, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($cashTransaction);
    }
}
