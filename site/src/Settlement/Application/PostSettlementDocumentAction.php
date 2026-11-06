<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Settlement\Application\DTO\SettlementResult;
use App\Settlement\Application\Service\SettlementRegisterUpdater;
use App\Settlement\Domain\SettlementMutationPolicy;
use App\Settlement\Entity\SettlementItem;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Enum\SettlementDocumentStatus;
use App\Settlement\Repository\SettlementRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PostSettlementDocumentAction
{
    public function __construct(private EntityManagerInterface $em, private SettlementMutationPolicy $policy, private SettlementRepository $repository, private SettlementRegisterUpdater $updater)
    {
    }

    public function __invoke(string $companyId, string $actorUserId, string $documentId, int $expectedVersion): SettlementResult
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $documentId, $expectedVersion): SettlementResult {
            $book = $this->policy->lock($companyId, $actorUserId);
            $document = $this->repository->getDocumentForUpdate($companyId, $documentId);
            if (!in_array($document->getKind(), [SettlementDocumentKind::OBLIGATION, SettlementDocumentKind::OPENING], true)) {
                throw new \DomainException('Тип документа требует отдельного сценария проведения.');
            }
            if (SettlementDocumentStatus::POSTED === $document->getStatus()) {
                return new SettlementResult($documentId, $this->repository->findItemByDocument($companyId, $documentId)?->getId(), $document->getVersion(), true);
            }
            $this->policy->assertDate($companyId, $book, $document->getEffectiveDate());
            $document->post($expectedVersion);
            $item = new SettlementItem($companyId, $documentId, $document->getCounterpartyId(), $document->getBucket(), $document->getAmount(), $document->getEffectiveDate(), $document->getDueDate());
            $this->em->persist($item);
            $this->updater->append($document, $item, $item->getAmount());
            $book->initialize();

            return new SettlementResult($documentId, $item->getId(), $document->getVersion(), false);
        });
    }
}
