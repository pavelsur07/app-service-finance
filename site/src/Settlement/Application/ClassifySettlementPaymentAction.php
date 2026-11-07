<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Settlement\Application\DTO\ReverseSettlementInput;
use App\Settlement\Application\DTO\SettlementResult;
use App\Settlement\Application\Service\SettlementRegisterUpdater;
use App\Settlement\Domain\SettlementMutationPolicy;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Enum\SettlementSourceType;
use App\Settlement\Repository\SettlementRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ClassifySettlementPaymentAction
{
    public function __construct(private EntityManagerInterface $em, private SettlementMutationPolicy $mutation, private SettlementRepository $repository, private SettlementRegisterUpdater $updater)
    {
    }

    public function __invoke(string $companyId, string $actorUserId, string $paymentItemId, bool $isAdvance, ReverseSettlementInput $input): SettlementResult
    {
        if (!\Ramsey\Uuid\Uuid::isValid($paymentItemId)) {
            throw new \DomainException('Некорректный идентификатор оплаты.');
        }

        return $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $paymentItemId, $isAdvance, $input): SettlementResult {
            $book = $this->mutation->lock($companyId, $actorUserId);
            $item = $this->repository->getItemForUpdate($companyId, $paymentItemId);
            $existing = $this->repository->findByRequest($companyId, $input->requestId);
            if (null !== $existing) {
                if (SettlementDocumentKind::CLASSIFICATION !== $existing->getKind() || ($existing->getSourceSnapshot()['itemId'] ?? null) !== $paymentItemId || ($existing->getSourceSnapshot()['isAdvance'] ?? null) !== $isAdvance || $existing->getEffectiveDate()->format('Y-m-d') !== $input->effectiveDate->format('Y-m-d') || $existing->getReason() !== $input->reason) {
                    throw new \DomainException('Конфликт ключа повтора.');
                }

                return new SettlementResult($existing->getId(), $paymentItemId, $existing->getVersion(), true);
            }
            $this->mutation->assertDate($companyId, $book, $input->effectiveDate);
            if ($input->effectiveDate->format('Y-m-d') < $item->getEffectiveDate()->format('Y-m-d')) {
                throw new \DomainException('Классификация раньше оплаты.');
            }
            $outgoing = in_array($item->getBucket(), [SettlementBucket::ADVANCE_GIVEN, SettlementBucket::UNALLOCATED_OUT], true);
            if (!$outgoing && !in_array($item->getBucket(), [SettlementBucket::ADVANCE_RECEIVED, SettlementBucket::UNALLOCATED_IN], true)) {
                throw new \DomainException('Классифицировать можно только оплату.');
            }
            $advance = $outgoing ? SettlementBucket::ADVANCE_GIVEN : SettlementBucket::ADVANCE_RECEIVED;
            $unallocated = $outgoing ? SettlementBucket::UNALLOCATED_OUT : SettlementBucket::UNALLOCATED_IN;
            $from = $isAdvance ? $unallocated : $advance;
            $to = $isAdvance ? $advance : $unallocated;
            $balances = $this->updater->balancesAt($companyId, $paymentItemId, $input->effectiveDate);
            $amount = $balances[$from->value];
            if (!$amount->isPositive()) {
                throw new \DomainException('Нет свободного остатка для переклассификации.');
            }
            $document = new SettlementDocument($companyId, $input->requestId, SettlementDocumentKind::CLASSIFICATION, $item->getCounterpartyId(), $to, $amount, $input->effectiveDate, null, $input->reason, $actorUserId, SettlementSourceType::MANUAL, null, ['itemId' => $paymentItemId, 'isAdvance' => $isAdvance, 'fromBucket' => $from->value, 'toBucket' => $to->value]);
            $document->post(1, $book->takeEventSequence());
            $this->em->persist($document);
            $this->updater->append($document, $item, $amount->negate(), $from);
            $this->updater->append($document, $item, $amount, $to);
            $this->em->flush();
            $this->updater->assertHistory($companyId, $paymentItemId);

            return new SettlementResult($document->getId(), $paymentItemId, $document->getVersion(), false);
        });
    }
}
