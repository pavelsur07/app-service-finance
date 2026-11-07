<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Company\Facade\CounterpartyFacade;
use App\Finance\Facade\FinanceFacade;
use App\Settlement\Application\DTO\CreateSettlementFromFinanceInput;
use App\Settlement\Application\DTO\SettlementResult;
use App\Settlement\Domain\SettlementFinanceSourcePolicy;
use App\Settlement\Domain\SettlementMutationPolicy;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Enum\SettlementSourceType;
use App\Settlement\Repository\SettlementRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CreateSettlementFromFinanceAction
{
    public function __construct(private EntityManagerInterface $em, private SettlementMutationPolicy $policy, private SettlementRepository $repository, private FinanceFacade $finance, private SettlementFinanceSourcePolicy $sourcePolicy, private CounterpartyFacade $parties)
    {
    }

    public function __invoke(string $companyId, string $actorUserId, CreateSettlementFromFinanceInput $input): SettlementResult
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $input): SettlementResult {
            $this->policy->requireWrite($companyId, $actorUserId);
            $source = $this->finance->getSettlementSourceForUpdate($companyId, $input->documentId);
            $this->repository->getBookForUpdate($companyId);
            $this->sourcePolicy->assertUsable($source);
            if (!in_array($input->bucket, [SettlementBucket::RECEIVABLE, SettlementBucket::PAYABLE], true) || null === $source->counterpartyId || null === $this->parties->findEntityByIdAndCompany($source->counterpartyId, $companyId)) {
                throw new \DomainException('Неверное основание или контрагент.');
            }
            $existing = $this->repository->findByRequest($companyId, $input->requestId);
            if (null !== $existing) {
                if (SettlementSourceType::FINANCE !== $existing->getSourceType() || $existing->getSourceId() !== $input->documentId || $existing->getBucket() !== $input->bucket || !$existing->getAmount()->equals($input->amount) || $existing->getEffectiveDate()->format('Y-m-d') !== $input->effectiveDate->format('Y-m-d') || $existing->getDueDate()?->format('Y-m-d') !== $input->dueDate?->format('Y-m-d') || $existing->getReason() !== $input->reason) {
                    throw new \DomainException('Конфликт ключа повтора.');
                }

                return new SettlementResult($existing->getId(), $this->repository->findItemByDocument($companyId, $existing->getId())?->getId(), $existing->getVersion(), true);
            }
            $document = new SettlementDocument($companyId, $input->requestId, SettlementDocumentKind::OBLIGATION, $source->counterpartyId, $input->bucket, $input->amount, $input->effectiveDate, $input->dueDate, $input->reason, $actorUserId, SettlementSourceType::FINANCE, $source->id, ['fingerprint' => $source->fingerprint]);
            $this->em->persist($document);

            return new SettlementResult($document->getId(), null, $document->getVersion(), false);
        });
    }
}
