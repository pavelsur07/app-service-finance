<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RestoreDocumentAction
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private EntityManagerInterface $entityManager,
        private PLRegisterUpdater $plRegisterUpdater,
    ) {
    }

    public function __invoke(string $companyId, string $documentId): void
    {
        $document = $this->documentRepository->findByIdAndCompany($documentId, $companyId)
            ?? throw new \DomainException('Документ ОПиУ не найден.');

        if (!$document->isDeleted()) {
            return;
        }

        $cashTransaction = $document->getCashTransaction();
        $documentAmount = $document->getTotalAmount();
        if (null !== $cashTransaction && $documentAmount > 0.0) {
            $cashTransaction->assertCanAllocateAmount($documentAmount);
        }

        $document->restore();
        $cashTransaction?->recalculateAllocatedAmount();
        $this->entityManager->flush();

        $day = $document->getDate()->setTime(0, 0);
        $this->plRegisterUpdater->recalcRange($document->getCompany(), $day, $day);
    }
}
