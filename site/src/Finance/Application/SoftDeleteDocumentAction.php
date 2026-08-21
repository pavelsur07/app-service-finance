<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Repository\DocumentRepository;
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
        $document = $this->documentRepository->findByIdAndCompany($documentId, $companyId)
            ?? throw new \DomainException('Документ ОПиУ не найден.');

        if ($document->isDeleted()) {
            return;
        }

        $document->markDeleted($actorUserId, $reason);
        $document->getCashTransaction()?->recalculateAllocatedAmount();
        $this->entityManager->flush();

        $day = $document->getDate()->setTime(0, 0);
        $this->plRegisterUpdater->recalcRange($document->getCompany(), $day, $day);
    }
}
