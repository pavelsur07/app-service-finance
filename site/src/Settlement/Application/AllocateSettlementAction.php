<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Settlement\Application\DTO\AllocateSettlementInput;
use App\Settlement\Application\Service\SettlementRegisterUpdater;
use App\Settlement\Domain\SettlementMutationPolicy;
use App\Settlement\Domain\SettlementPostingPolicy;
use App\Settlement\Entity\SettlementAllocation;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Enum\SettlementSourceType;
use App\Settlement\Repository\SettlementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class AllocateSettlementAction
{
    public function __construct(private EntityManagerInterface $em, private SettlementMutationPolicy $mutation, private SettlementPostingPolicy $posting, private SettlementRepository $repository, private SettlementRegisterUpdater $updater)
    {
    }

    public function __invoke(string $companyId, string $actorUserId, AllocateSettlementInput $input): string
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $input): string {
            $book = $this->mutation->lock($companyId, $actorUserId);
            $ids = [$input->obligationItemId, $input->paymentItemId];
            sort($ids);
            $items = [];
            foreach ($ids as $id) {
                $items[$id] = $this->repository->getItemForUpdate($companyId, $id);
            }
            $debt = $items[$input->obligationItemId];
            $payment = $items[$input->paymentItemId];
            $existing = $this->repository->findByRequest($companyId, $input->requestId);
            if (null !== $existing) {
                if (SettlementDocumentKind::ALLOCATION !== $existing->getKind()) {
                    throw new \DomainException('Конфликт ключа повтора.');
                }
                $allocation = $this->repository->getAllocationByDocument($companyId, $existing->getId());
                if ($allocation->getObligationItemId() !== $input->obligationItemId || $allocation->getPaymentItemId() !== $input->paymentItemId || !$allocation->getAmount()->equals($input->amount) || $allocation->getEffectiveDate()->format('Y-m-d') !== $input->effectiveDate->format('Y-m-d')) {
                    throw new \DomainException('Конфликт ключа повтора.');
                }

                return $allocation->getId();
            }
            $this->mutation->assertDate($companyId, $book, $input->effectiveDate);
            $debtBalances = $this->updater->balancesAt($companyId, $debt->getId(), $input->effectiveDate);
            $paymentBalances = $this->updater->balancesAt($companyId, $payment->getId(), $input->effectiveDate);
            $paymentBucket = null;
            foreach ([SettlementBucket::ADVANCE_GIVEN, SettlementBucket::UNALLOCATED_OUT, SettlementBucket::ADVANCE_RECEIVED, SettlementBucket::UNALLOCATED_IN] as $bucket) {
                if (!$paymentBalances[$bucket->value]->isPositive()) {
                    continue;
                }
                if (null !== $paymentBucket) {
                    throw new \DomainException('Сначала явно классифицируйте свободный остаток оплаты.');
                }
                $paymentBucket = $bucket;
            }
            if (null === $paymentBucket) {
                throw new \DomainException('Нет свободного остатка оплаты.');
            }
            $this->posting->assertAllocation($debt, $payment, $input->amount, $input->effectiveDate, $debtBalances[$debt->getBucket()->value], $paymentBalances[$paymentBucket->value]);
            $document = new SettlementDocument($companyId, $input->requestId, SettlementDocumentKind::ALLOCATION, $debt->getCounterpartyId(), $debt->getBucket(), $input->amount, $input->effectiveDate, null, 'Погашение задолженности', $actorUserId, SettlementSourceType::MANUAL, null, ['paymentBucket' => $paymentBucket->value]);
            $document->post(1, $book->takeEventSequence());
            $allocation = new SettlementAllocation($companyId, $document->getId(), $debt->getId(), $payment->getId(), $input->amount, $input->effectiveDate, Uuid::uuid7()->toString());
            $this->em->persist($document);
            $this->em->persist($allocation);
            $this->updater->append($document, $debt, $input->amount->negate());
            $this->updater->append($document, $payment, $input->amount->negate(), $paymentBucket);
            $this->em->flush();
            $this->updater->assertHistory($companyId, $debt->getId());
            $this->updater->assertHistory($companyId, $payment->getId());

            return $allocation->getId();
        });
    }
}
