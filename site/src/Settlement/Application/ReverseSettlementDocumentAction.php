<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Cash\Facade\CashFacade;
use App\Finance\Facade\FinanceFacade;
use App\Settlement\Application\DTO\ReverseSettlementInput;
use App\Settlement\Application\DTO\SettlementResult;
use App\Settlement\Application\Service\SettlementRegisterUpdater;
use App\Settlement\Domain\SettlementMutationPolicy;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Entity\SettlementMovement;
use App\Settlement\Entity\SettlementSourceLink;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Enum\SettlementDocumentStatus;
use App\Settlement\Enum\SettlementSourceType;
use App\Settlement\Repository\SettlementRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReverseSettlementDocumentAction
{
    public function __construct(private EntityManagerInterface $em, private SettlementMutationPolicy $policy, private SettlementRepository $repository, private SettlementRegisterUpdater $updater, private CashFacade $cash, private FinanceFacade $finance)
    {
    }

    public function __invoke(string $companyId, string $actorUserId, string $documentId, ReverseSettlementInput $input): SettlementResult
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $documentId, $input): SettlementResult {
            $this->policy->requireWrite($companyId, $actorUserId);
            $priorReversal = $this->em->getRepository(SettlementDocument::class)->findOneBy(['companyId' => $companyId, 'reversedDocumentId' => $documentId]);
            if (null !== $priorReversal) {
                return new SettlementResult($priorReversal->getId(), null, $priorReversal->getVersion(), true);
            }
            $hint = $this->em->getRepository(SettlementDocument::class)->findOneBy(['companyId' => $companyId, 'id' => $documentId]);
            if (null === $hint) {
                throw new \DomainException('Документ не найден.');
            }
            if (SettlementSourceType::CASH === $hint->getSourceType() && null !== $hint->getSourceId()) {
                $this->cash->getSettlementSourceForUpdate($companyId, $hint->getSourceId());
            }
            if (SettlementSourceType::FINANCE === $hint->getSourceType() && null !== $hint->getSourceId()) {
                $this->finance->getSettlementSourceForUpdate($companyId, $hint->getSourceId());
            }
            $book = $this->repository->getBookForUpdate($companyId);
            $original = $this->repository->getDocumentForUpdate($companyId, $documentId);
            $existing = $this->em->getRepository(SettlementDocument::class)->findOneBy(['companyId' => $companyId, 'reversedDocumentId' => $documentId]);
            if (null !== $existing) {
                return new SettlementResult($existing->getId(), null, $existing->getVersion(), true);
            }
            if (SettlementDocumentStatus::POSTED !== $original->getStatus() || null !== $original->getReversedDocumentId()) {
                throw new \DomainException('Отмена допустима только для исходного проведённого документа.');
            }
            if (null !== $this->repository->findByRequest($companyId, $input->requestId)) {
                throw new \DomainException('Ключ отмены уже занят.');
            }
            $this->policy->assertDate($companyId, $book, $input->effectiveDate);
            if ($input->effectiveDate->format('Y-m-d') < $original->getEffectiveDate()->format('Y-m-d')) {
                throw new \DomainException('Отмена раньше исходного события.');
            }
            $movements = $this->em->getRepository(SettlementMovement::class)->findBy(['companyId' => $companyId, 'documentId' => $documentId]);
            $items = [];
            foreach ($movements as $movement) {
                $items[$movement->getItemId()] = $movement->getItemId();
            }
            ksort($items);
            foreach ($items as $itemId) {
                $this->repository->getItemForUpdate($companyId, $itemId);
                if (in_array($original->getKind(), [SettlementDocumentKind::OBLIGATION, SettlementDocumentKind::OPENING, SettlementDocumentKind::PAYMENT], true)) {
                    $dependent = $this->em->getConnection()->fetchOne("SELECT 1 FROM settlement_movements m JOIN settlement_documents d ON d.company_id=m.company_id AND d.id=m.document_id LEFT JOIN settlement_documents r ON r.company_id=d.company_id AND r.reversed_document_id=d.id WHERE m.company_id=:company AND m.item_id=:item AND d.id<>:original AND d.kind NOT IN ('document_reversal','allocation_reversal') AND (r.id IS NULL OR r.effective_date>:date) LIMIT 1", ['company' => $companyId, 'item' => $itemId, 'original' => $documentId, 'date' => $input->effectiveDate->format('Y-m-d')]);
                    if (false !== $dependent) {
                        throw new \DomainException('Сначала отмените зависимые события с допустимой датой.');
                    }
                }
            }
            $reversal = new SettlementDocument($companyId, $input->requestId, SettlementDocumentKind::DOCUMENT_REVERSAL, $original->getCounterpartyId(), $original->getBucket(), $original->getAmount(), $input->effectiveDate, null, $input->reason, $actorUserId, SettlementSourceType::MANUAL, null, [], $documentId);
            $reversal->post(1, $book->takeEventSequence());
            $this->em->persist($reversal);
            foreach ($movements as $movement) {
                $this->em->persist(new SettlementMovement($companyId, $reversal->getId(), $movement->getItemId(), $movement->getBucket(), $movement->getDelta()->negate(), $input->effectiveDate));
            }
            if (SettlementSourceType::MANUAL !== $original->getSourceType() && null !== $original->getSourceId()) {
                $link = $this->em->getRepository(SettlementSourceLink::class)->findOneBy(['companyId' => $companyId, 'sourceType' => $original->getSourceType(), 'sourceId' => $original->getSourceId()]);
                if (null === $link || $link->getActiveDocumentId() !== $documentId) {
                    throw new \DomainException('Активная связь источника не соответствует документу.');
                }
                $link->deactivate($input->effectiveDate);
            }
            $this->em->flush();
            foreach ($items as $itemId) {
                $this->updater->assertHistory($companyId, $itemId);
            }

            return new SettlementResult($reversal->getId(), null, $reversal->getVersion(), false);
        });
    }
}
