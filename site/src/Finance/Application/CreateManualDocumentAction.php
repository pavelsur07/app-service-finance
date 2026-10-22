<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ручной документ ОПиУ из формы (создание и копия): документ и регистр — один commit, сбой
 * пересчёта не оставляет документ без строк регистра.
 */
final readonly class CreateManualDocumentAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PLRegisterUpdater $plRegisterUpdater,
    ) {
    }

    public function __invoke(Document $document): void
    {
        $this->entityManager->wrapInTransaction(function () use ($document): void {
            $this->entityManager->persist($document);
            $this->entityManager->flush();

            $this->plRegisterUpdater->updateForDocument($document);
        });

        $this->plRegisterUpdater->invalidateCacheForCompanyId((string) $document->getCompany()->getId());
    }
}
