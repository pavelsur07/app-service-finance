<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Finance\Domain\Event\FinanceSettlementSourceChanging;
use App\Finance\Entity\Document;
use App\Finance\Infrastructure\Query\FinanceSettlementSourceQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class FinanceSettlementSourceLifecycle
{
    public function __construct(private FinanceSettlementSourceQuery $query, private EntityManagerInterface $em, private EventDispatcherInterface $events)
    {
    }

    public function changing(Document $document, bool $compareEditedDocument = false): void
    {
        $companyId = (string) $document->getCompany()->getId();
        $id = (string) $document->getId();
        $material = true;
        if ($compareEditedDocument) {
            $stored = $this->query->getForUpdate($companyId, $id);
            $material = $stored->fingerprint !== $this->query->fingerprintDocument($document);
        } else {
            // Delete/restore are always material; do not load huge marketplace operation sets.
            $this->query->lockHeaderForUpdate($companyId, $id);
        }
        if (!$compareEditedDocument) {
            $this->em->refresh($document);
        }
        $this->events->dispatch(new FinanceSettlementSourceChanging($companyId, $id, $material));
    }
}
