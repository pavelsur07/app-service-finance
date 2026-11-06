<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Facade\CashFacade;
use App\Settlement\Application\DTO\RegisterCashSettlementInput;
use App\Settlement\Application\DTO\SettlementResult;
use App\Settlement\Application\Service\SettlementRegisterUpdater;
use App\Settlement\Domain\SettlementMutationPolicy;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Entity\SettlementItem;
use App\Settlement\Entity\SettlementSourceLink;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Enum\SettlementSourceType;
use App\Settlement\Repository\SettlementRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RegisterCashSettlementAction
{
    public function __construct(private EntityManagerInterface $em, private SettlementMutationPolicy $policy, private SettlementRepository $repository, private SettlementRegisterUpdater $updater, private CashFacade $cash, private \App\Company\Facade\CounterpartyFacade $parties)
    {
    }

    public function __invoke(string $companyId, string $actorUserId, RegisterCashSettlementInput $input): SettlementResult
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $input): SettlementResult {
            $this->policy->requireWrite($companyId, $actorUserId);
            $source = $this->cash->getSettlementSourceForUpdate($companyId, $input->cashTransactionId);
            $book = $this->repository->getBookForUpdate($companyId);
            $existing = $this->repository->findByRequest($companyId, $input->requestId);
            if (null !== $existing) {
                if (SettlementDocumentKind::PAYMENT !== $existing->getKind() || SettlementSourceType::CASH !== $existing->getSourceType() || $existing->getSourceId() !== $input->cashTransactionId || ($existing->getSourceSnapshot()['isAdvance'] ?? null) !== $input->isAdvance || $existing->getEffectiveDate()->format('Y-m-d') !== $input->effectiveDate->format('Y-m-d')) {
                    throw new \DomainException('Конфликт ключа повтора.');
                }

                return new SettlementResult($existing->getId(), $this->repository->findItemByDocument($companyId, $existing->getId())?->getId(), $existing->getVersion(), true);
            }
            if (null === $source->counterpartyId || null === $this->parties->findEntityByIdAndCompany($source->counterpartyId, $companyId) || $source->deleted || $source->isTransfer || 'RUB' !== $source->amount->currency() || !$source->amount->isPositive()) {
                throw new \DomainException('Транзакция не подходит для взаиморасчётов.');
            }
            $this->policy->assertDate($companyId, $book, $input->effectiveDate);
            $link = $this->em->getRepository(SettlementSourceLink::class)->findOneBy(['companyId' => $companyId, 'sourceType' => SettlementSourceType::CASH, 'sourceId' => $source->id]);
            if (null !== $link) {
                $this->em->refresh($link);
            }
            $lastReversal = $link?->getLastReversalDate();
            $day = $input->effectiveDate->format('Y-m-d');
            if ($source->occurredAt->format('Y-m-d') < $book->getStartDate()->format('Y-m-d') || (null === $lastReversal && $day !== $source->occurredAt->format('Y-m-d')) || (null !== $lastReversal && ($day < $lastReversal->format('Y-m-d') || $day < $source->occurredAt->format('Y-m-d')))) {
                throw new \DomainException('Дата включения раньше источника или последней отмены.');
            }
            $bucket = match ($source->direction) {
                CashDirection::OUTFLOW => $input->isAdvance ? SettlementBucket::ADVANCE_GIVEN : SettlementBucket::UNALLOCATED_OUT,
                CashDirection::INFLOW => $input->isAdvance ? SettlementBucket::ADVANCE_RECEIVED : SettlementBucket::UNALLOCATED_IN,
            };
            $document = new SettlementDocument($companyId, $input->requestId, SettlementDocumentKind::PAYMENT, $source->counterpartyId, $bucket, $source->amount, $input->effectiveDate, null, 'Оплата ДДС', $actorUserId, SettlementSourceType::CASH, $source->id, ['fingerprint' => $source->fingerprint, 'occurredAt' => $source->occurredAt->format('Y-m-d'), 'isAdvance' => $input->isAdvance]);
            $document->post(1, $book->takeEventSequence());
            $item = new SettlementItem($companyId, $document->getId(), $source->counterpartyId, $bucket, $source->amount, $input->effectiveDate);
            $link ??= new SettlementSourceLink($companyId, SettlementSourceType::CASH, $source->id);
            $link->activate($document->getId());
            $this->em->persist($document);
            $this->em->persist($item);
            $this->updater->append($document, $item, $source->amount);
            $this->em->persist($link);
            $book->initialize();

            return new SettlementResult($document->getId(), $item->getId(), $document->getVersion(), false);
        });
    }
}
