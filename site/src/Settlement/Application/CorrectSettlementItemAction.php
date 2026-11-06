<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Settlement\Application\DTO\CorrectSettlementItemInput;
use App\Settlement\Application\DTO\SettlementResult;
use App\Settlement\Application\Service\SettlementRegisterUpdater;
use App\Settlement\Domain\SettlementMutationPolicy;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Enum\SettlementSourceType;
use App\Settlement\Repository\SettlementRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CorrectSettlementItemAction
{
    public function __construct(private EntityManagerInterface $em, private SettlementMutationPolicy $policy, private SettlementRepository $repository, private SettlementRegisterUpdater $updater)
    {
    }

    public function __invoke(string $companyId, string $actorUserId, CorrectSettlementItemInput $input): SettlementResult
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $input): SettlementResult {
            $book = $this->policy->lock($companyId, $actorUserId);
            $item = $this->repository->getItemForUpdate($companyId, $input->itemId);
            $snapshot = ['itemId' => $input->itemId, 'increase' => $input->increase];
            $existing = $this->repository->findByRequest($companyId, $input->requestId);
            if (null !== $existing) {
                if (SettlementDocumentKind::CORRECTION !== $existing->getKind() || $existing->getSourceSnapshot() !== $snapshot || !$existing->getAmount()->equals($input->amount) || $existing->getEffectiveDate()->format('Y-m-d') !== $input->effectiveDate->format('Y-m-d') || $existing->getReason() !== $input->reason) {
                    throw new \DomainException('Конфликт ключа повтора.');
                }

                return new SettlementResult($existing->getId(), $item->getId(), $existing->getVersion(), true);
            }
            if (null !== $this->em->getRepository(SettlementDocument::class)->findOneBy(['companyId' => $companyId, 'reversedDocumentId' => $item->getDocumentId()])) {
                throw new \DomainException('Нельзя корректировать отменённую позицию.');
            }
            $this->policy->assertDate($companyId, $book, $input->effectiveDate);
            if ($input->effectiveDate->format('Y-m-d') < $item->getEffectiveDate()->format('Y-m-d')) {
                throw new \DomainException('Корректировка раньше исходной позиции.');
            }
            $document = new SettlementDocument($companyId, $input->requestId, SettlementDocumentKind::CORRECTION, $item->getCounterpartyId(), $item->getBucket(), $input->amount, $input->effectiveDate, null, $input->reason, $actorUserId, SettlementSourceType::MANUAL, null, $snapshot);
            $document->post(1);
            $this->em->persist($document);
            $this->updater->append($document, $item, $input->increase ? $input->amount : $input->amount->negate());
            $this->em->flush();
            $this->updater->assertHistory($companyId, $item->getId());

            return new SettlementResult($document->getId(), $item->getId(), $document->getVersion(), false);
        });
    }
}
