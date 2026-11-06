<?php

declare(strict_types=1);

namespace App\Settlement\Query;

use App\Settlement\Application\DTO\SettlementRegisterProjection;
use App\Settlement\Domain\SettlementPostingPolicy;
use App\Settlement\Entity\SettlementAllocation;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Entity\SettlementItem;
use App\Settlement\Entity\SettlementMovement;
use App\Settlement\Entity\SettlementSourceLink;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Enum\SettlementDocumentStatus;
use App\Settlement\Enum\SettlementSourceType;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;

/** Replays immutable primary facts, never reads settlement_movements or source_links. */
final readonly class SettlementRegisterProjectionQuery
{
    public function __construct(private EntityManagerInterface $em, private SettlementPostingPolicy $posting, private \App\Settlement\Domain\SettlementMutationPolicy $dates)
    {
    }

    public function project(string $companyId): SettlementRegisterProjection
    {
        $book = $this->em->getRepository(\App\Settlement\Entity\SettlementBook::class)->findOneBy(['companyId' => $companyId]) ?? throw new \DomainException('Настройте начало учёта.');
        $this->em->refresh($book);
        $documents = $this->em->createQuery('SELECT d FROM '.SettlementDocument::class.' d WHERE d.companyId=:company AND d.status=:status ORDER BY d.eventSequence ASC')->setParameter('company', $companyId)->setParameter('status', SettlementDocumentStatus::POSTED)->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)->getResult();
        $items = [];
        $itemsByDocument = [];
        foreach ($this->em->createQuery('SELECT i FROM '.SettlementItem::class.' i WHERE i.companyId=:company')->setParameter('company', $companyId)->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)->getResult() as $item) {
            $items[$item->getId()] = $item;
            $itemsByDocument[$item->getDocumentId()] = $item;
        }
        $allocations = [];
        foreach ($this->em->createQuery('SELECT a FROM '.SettlementAllocation::class.' a WHERE a.companyId=:company')->setParameter('company', $companyId)->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)->getResult() as $allocation) {
            $allocations[$allocation->getDocumentId()] = $allocation;
        }
        $events = [];
        $links = [];
        $active = [];
        $sequences = [];
        $primary = [];
        $usedItems = [];
        $usedAllocations = [];
        $reversals = [];
        foreach ($documents as $doc) {
            $id = $doc->getId();
            $snapshot = $doc->getSourceSnapshot();
            $sequences[$id] = $doc->getEventSequence();
            $event = [];
            $this->dates->assertHistoryDate($book, $doc->getEffectiveDate());
            if (!in_array($doc->getKind(), [SettlementDocumentKind::OPENING, SettlementDocumentKind::OBLIGATION, SettlementDocumentKind::PAYMENT], true) && (SettlementSourceType::MANUAL !== $doc->getSourceType() || null !== $doc->getSourceId())) {
                throw new \DomainException('Источник служебного события не согласован.');
            }
            if (!$doc->getAmount()->isPositive() || 'RUB' !== $doc->getAmount()->currency() || null === $doc->getEventSequence() || $doc->getEventSequence() < 1 || '' === trim($doc->getReason())) {
                throw new \DomainException('Повреждён первичный документ.');
            }
            if (null !== $doc->getReversedDocumentId()) {
                $reversals[$doc->getReversedDocumentId()] = $doc;
                $target = $primary[$doc->getReversedDocumentId()] ?? throw new \DomainException('Первичный документ отмены отсутствует.');
                if (null !== $target->getReversedDocumentId() || $doc->getKind() !== (SettlementDocumentKind::ALLOCATION === $target->getKind() ? SettlementDocumentKind::ALLOCATION_REVERSAL : SettlementDocumentKind::DOCUMENT_REVERSAL) || $doc->getEffectiveDate()->format('Y-m-d') < $target->getEffectiveDate()->format('Y-m-d') || !$doc->getAmount()->equals($target->getAmount()) || $doc->getBucket() !== $target->getBucket() || $doc->getCounterpartyId() !== $target->getCounterpartyId()) {
                    throw new \DomainException('Первичная отмена не согласована с исходным событием.');
                }
                $original = $events[$doc->getReversedDocumentId()] ?? throw new \DomainException('Повреждена последовательность первичных отмен.');
                foreach ($original as $movement) {
                    $event[] = new SettlementMovement($companyId, $id, $movement->getItemId(), $movement->getBucket(), $movement->getDelta()->negate(), $doc->getEffectiveDate());
                }
                foreach ($links as $key => $link) {
                    if ($link->getActiveDocumentId() === $doc->getReversedDocumentId()) {
                        $link->deactivate($doc->getEffectiveDate());
                        unset($active[$key]);
                    }
                }
            } else {
                switch ($doc->getKind()) {
                    case SettlementDocumentKind::OPENING:
                    case SettlementDocumentKind::OBLIGATION:
                    case SettlementDocumentKind::PAYMENT:
                        $this->posting->assertBaseDocument($doc->getKind(), $doc->getBucket(), $doc->getSourceType(), $doc->getSourceId(), $doc->getEffectiveDate(), $book->getStartDate());
                        if (SettlementDocumentKind::PAYMENT === $doc->getKind()) {
                            $advance = $snapshot['isAdvance'] ?? null;
                            $day = $snapshot['occurredAt'] ?? null;
                            $sourceDate = is_string($day) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $day) : false;
                            if (!is_bool($advance) || false === $sourceDate || $sourceDate->format('Y-m-d') !== $day || $day < $book->getStartDate()->format('Y-m-d') || $day > $doc->getEffectiveDate()->format('Y-m-d') || $advance !== in_array($doc->getBucket(), [SettlementBucket::ADVANCE_GIVEN, SettlementBucket::ADVANCE_RECEIVED], true)) {
                                throw new \DomainException('Снимок первичной оплаты не согласован.');
                            }
                            if (null === ($links['cash:'.$doc->getSourceId()] ?? null)?->getLastReversalDate() && $day !== $doc->getEffectiveDate()->format('Y-m-d')) {
                                throw new \DomainException('Дата первого включения оплаты не соответствует ДДС.');
                            }
                        }
                        $item = $itemsByDocument[$id] ?? throw new \DomainException('У первичного документа отсутствует позиция.');
                        $usedItems[$item->getId()] = true;
                        if (!$doc->getAmount()->equals($item->getAmount()) || $doc->getBucket() !== $item->getBucket() || $doc->getCounterpartyId() !== $item->getCounterpartyId() || $doc->getEffectiveDate()->format('Y-m-d') !== $item->getEffectiveDate()->format('Y-m-d') || $doc->getDueDate()?->format('Y-m-d') !== $item->getDueDate()?->format('Y-m-d')) {
                            throw new \DomainException('Первичный документ и позиция не согласованы.');
                        }
                        $event[] = new SettlementMovement($companyId, $id, $item->getId(), $doc->getBucket(), $doc->getAmount(), $doc->getEffectiveDate());
                        break;
                    case SettlementDocumentKind::CORRECTION:
                        $item = $items[(string) ($snapshot['itemId'] ?? '')] ?? throw new \DomainException('Позиция корректировки отсутствует.');
                        if (SettlementDocumentKind::PAYMENT === ($primary[$item->getDocumentId()] ?? null)?->getKind() || $doc->getBucket() !== $item->getBucket() || $doc->getCounterpartyId() !== $item->getCounterpartyId() || $doc->getEffectiveDate()->format('Y-m-d') < $item->getEffectiveDate()->format('Y-m-d')) {
                            throw new \DomainException('Первичная корректировка не согласована с позицией.');
                        }
                        if (!is_bool($snapshot['increase'] ?? null)) {
                            throw new \DomainException('У корректировки отсутствует направление.');
                        }
                        $event[] = new SettlementMovement($companyId, $id, $item->getId(), $item->getBucket(), $snapshot['increase'] ? $doc->getAmount() : $doc->getAmount()->negate(), $doc->getEffectiveDate());
                        break;
                    case SettlementDocumentKind::CLASSIFICATION:
                        $item = $items[(string) ($snapshot['itemId'] ?? '')] ?? throw new \DomainException('Позиция классификации отсутствует.');
                        $from = SettlementBucket::tryFrom((string) ($snapshot['fromBucket'] ?? '')) ?? throw new \DomainException('Неизвестная корзина классификации.');
                        $to = SettlementBucket::tryFrom((string) ($snapshot['toBucket'] ?? '')) ?? throw new \DomainException('Неизвестная корзина классификации.');
                        $outgoing = in_array($item->getBucket(), [SettlementBucket::ADVANCE_GIVEN, SettlementBucket::UNALLOCATED_OUT], true);
                        $incoming = in_array($item->getBucket(), [SettlementBucket::ADVANCE_RECEIVED, SettlementBucket::UNALLOCATED_IN], true);
                        $advance = $outgoing ? SettlementBucket::ADVANCE_GIVEN : SettlementBucket::ADVANCE_RECEIVED;
                        $unallocated = $outgoing ? SettlementBucket::UNALLOCATED_OUT : SettlementBucket::UNALLOCATED_IN;
                        $isAdvance = $snapshot['isAdvance'] ?? null;
                        if ((!$outgoing && !$incoming) || !is_bool($isAdvance) || $from !== ($isAdvance ? $unallocated : $advance) || $to !== ($isAdvance ? $advance : $unallocated) || $doc->getBucket() !== $to || $doc->getCounterpartyId() !== $item->getCounterpartyId() || $doc->getEffectiveDate()->format('Y-m-d') < $item->getEffectiveDate()->format('Y-m-d')) {
                            throw new \DomainException('Первичная классификация нарушает направление оплаты.');
                        }
                        $event[] = new SettlementMovement($companyId, $id, $item->getId(), $from, $doc->getAmount()->negate(), $doc->getEffectiveDate());
                        $event[] = new SettlementMovement($companyId, $id, $item->getId(), $to, $doc->getAmount(), $doc->getEffectiveDate());
                        break;
                    case SettlementDocumentKind::ALLOCATION:
                        $allocation = $allocations[$id] ?? throw new \DomainException('У события погашения отсутствует распределение.');
                        $usedAllocations[$allocation->getId()] = true;
                        $debt = $items[$allocation->getObligationItemId()] ?? throw new \DomainException('Основание погашения отсутствует.');
                        $payment = $items[$allocation->getPaymentItemId()] ?? throw new \DomainException('Платёж погашения отсутствует.');
                        $bucket = SettlementBucket::tryFrom((string) ($snapshot['paymentBucket'] ?? '')) ?? throw new \DomainException('Корзина оплаты отсутствует.');
                        $this->posting->assertAllocationPair($debt, $payment, $doc->getAmount(), $doc->getEffectiveDate());
                        $allowedBuckets = SettlementBucket::PAYABLE === $debt->getBucket() ? [SettlementBucket::ADVANCE_GIVEN, SettlementBucket::UNALLOCATED_OUT] : [SettlementBucket::ADVANCE_RECEIVED, SettlementBucket::UNALLOCATED_IN];
                        if ($doc->getBucket() !== $debt->getBucket() || $doc->getCounterpartyId() !== $debt->getCounterpartyId() || !in_array($bucket, $allowedBuckets, true)) {
                            throw new \DomainException('Первичное погашение нарушает направление долга.');
                        }
                        if (!$allocation->getAmount()->equals($doc->getAmount()) || $allocation->getEffectiveDate()->format('Y-m-d') !== $doc->getEffectiveDate()->format('Y-m-d') || $debt->getCounterpartyId() !== $payment->getCounterpartyId()) {
                            throw new \DomainException('Первичное распределение не согласовано.');
                        }
                        $event[] = new SettlementMovement($companyId, $id, $debt->getId(), $debt->getBucket(), $doc->getAmount()->negate(), $doc->getEffectiveDate());
                        $event[] = new SettlementMovement($companyId, $id, $payment->getId(), $bucket, $doc->getAmount()->negate(), $doc->getEffectiveDate());
                        break;
                    default:
                        throw new \DomainException('Первичная отмена не содержит ссылки на исходный документ.');
                }
                if (SettlementSourceType::MANUAL !== $doc->getSourceType()) {
                    if (!is_string($snapshot['fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $snapshot['fingerprint'])) {
                        throw new \DomainException('Снимок первичного источника отсутствует.');
                    }
                    $sourceId = $doc->getSourceId() ?? throw new \DomainException('Источник документа отсутствует.');
                    $key = $doc->getSourceType()->value.':'.$sourceId;
                    $link = $links[$key] ??= new SettlementSourceLink($companyId, $doc->getSourceType(), $sourceId);
                    if (null !== $link->getLastReversalDate() && $link->getLastReversalDate()->format('Y-m-d') > $doc->getEffectiveDate()->format('Y-m-d')) {
                        throw new \DomainException('Нарушена хронология повторного включения.');
                    }
                    $link->activate($id);
                    $active[$key] = $doc;
                }
            }
            $events[$id] = $event;
            $primary[$id] = $doc;
        }
        if (count($usedItems) !== count($items) || count($usedAllocations) !== count($allocations)) {
            throw new \DomainException('Лишние первичные позиции или распределения без проведённого события.');
        }
        $dependencies = [];
        foreach ($events as $eventId => $event) {
            if (null !== $primary[$eventId]->getReversedDocumentId()) {
                continue;
            }
            foreach ($event as $movement) {
                $dependencies[$movement->getItemId()][$eventId] = true;
            }
        }
        foreach ($reversals as $targetId => $reversal) {
            if (!in_array($primary[$targetId]->getKind(), [SettlementDocumentKind::OPENING, SettlementDocumentKind::OBLIGATION, SettlementDocumentKind::PAYMENT], true)) {
                continue;
            }
            foreach ($events[$targetId] as $originalMovement) {
                foreach (array_keys($dependencies[$originalMovement->getItemId()] ?? []) as $dependentId) {
                    if ($dependentId === $targetId) {
                        continue;
                    }
                    $dependentReversal = $reversals[$dependentId] ?? null;
                    if (null === $dependentReversal || $dependentReversal->getEffectiveDate()->format('Y-m-d') > $reversal->getEffectiveDate()->format('Y-m-d')) {
                        throw new \DomainException('Отмена основания предшествует отмене зависимых событий.');
                    }
                }
            }
        }
        $movements = [];
        foreach ($events as $event) {
            array_push($movements, ...$event);
        }
        usort($movements, static fn (SettlementMovement $a, SettlementMovement $b): int => [$a->getEffectiveDate()->format('Y-m-d'), $sequences[$a->getDocumentId()], $a->getItemId(), $a->getBucket()->value] <=> [$b->getEffectiveDate()->format('Y-m-d'), $sequences[$b->getDocumentId()], $b->getItemId(), $b->getBucket()->value]);
        $balances = [];
        foreach ($movements as $movement) {
            $key = $movement->getItemId().':'.$movement->getBucket()->value;
            $balances[$key] = ($balances[$key] ?? Money::fromMinor(0, 'RUB'))->add($movement->getDelta());
            if ($balances[$key]->isNegative()) {
                throw new \DomainException('Первичные документы создают отрицательный остаток истории.');
            }
        }

        return new SettlementRegisterProjection($movements, array_values($links), $active, $book->getStartDate());
    }
}
