<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Company\Facade\CounterpartyFacade;
use App\Settlement\Application\DTO\CreateSettlementDocumentInput;
use App\Settlement\Application\DTO\SettlementResult;
use App\Settlement\Domain\SettlementMutationPolicy;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Repository\SettlementRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CreateSettlementDocumentAction
{
    public function __construct(private EntityManagerInterface $em, private SettlementMutationPolicy $policy, private SettlementRepository $repository, private CounterpartyFacade $parties, private \App\Settlement\Domain\SettlementPostingPolicy $posting)
    {
    }

    public function __invoke(string $companyId, string $actorUserId, CreateSettlementDocumentInput $input): SettlementResult
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $input): SettlementResult {
            $book = $this->policy->lock($companyId, $actorUserId);
            if (!in_array($input->kind, [SettlementDocumentKind::OPENING, SettlementDocumentKind::OBLIGATION], true)
                || null === $this->parties->findEntityByIdAndCompany($input->counterpartyId, $companyId)) {
                throw new \DomainException('Неверное основание или контрагент.');
            }
            $this->posting->assertBaseDocument($input->kind, $input->bucket, \App\Settlement\Enum\SettlementSourceType::MANUAL, null, $input->effectiveDate, $book->getStartDate());
            $existing = $this->repository->findByRequest($companyId, $input->requestId);
            if (null !== $existing) {
                if ($existing->getKind() !== $input->kind || $existing->getCounterpartyId() !== $input->counterpartyId || $existing->getBucket() !== $input->bucket || !$existing->getAmount()->equals($input->amount) || $existing->getEffectiveDate()->format('Y-m-d') !== $input->effectiveDate->format('Y-m-d') || $existing->getDueDate()?->format('Y-m-d') !== $input->dueDate?->format('Y-m-d') || $existing->getReason() !== $input->reason) {
                    throw new \DomainException('Ключ повтора использован с другими данными.');
                }

                return new SettlementResult($existing->getId(), $this->repository->findItemByDocument($companyId, $existing->getId())?->getId(), $existing->getVersion(), true);
            }
            $document = new SettlementDocument($companyId, $input->requestId, $input->kind, $input->counterpartyId, $input->bucket, $input->amount, $input->effectiveDate, $input->dueDate, $input->reason, $actorUserId);
            $this->em->persist($document);

            return new SettlementResult($document->getId(), null, $document->getVersion(), false);
        });
    }
}
