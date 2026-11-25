<?php

declare(strict_types=1);

namespace App\Cash\Application\Report;

use App\Cash\Domain\Policy\IntragroupMatcher;
use App\Cash\Entity\Transaction\CashIntragroupDecision;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\IntragroupDecisionType;
use App\Cash\Exception\IntragroupDecisionException;
use App\Cash\Infrastructure\Query\CashIntragroupQuery;
use App\Cash\Repository\Transaction\CashIntragroupDecisionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final readonly class IntragroupDecisionAction
{
    public function __construct(
        private EntityManagerInterface $em,
        private IntragroupGroupAccess $access,
        private CashIntragroupDecisionRepository $decisions,
        private CashIntragroupQuery $transactions,
        private BuildCashflowReconciliation $reconcile,
    ) {
    }

    public function save(string $activeCompanyId, string $userId, string $id, IntragroupDecisionType $type, string $outflowId, string $inflowId): void
    {
        $id = strtolower($id);
        $outflowId = strtolower($outflowId);
        $inflowId = strtolower($inflowId);
        try {
            $this->em->wrapInTransaction(function () use ($activeCompanyId, $userId, $id, $type, $outflowId, $inflowId): void {
                $members = $this->access->lockedMembers($activeCompanyId, $userId);
                if (!UuidV7::isValid($id) || !Uuid::isValid($outflowId) || !Uuid::isValid($inflowId) || $outflowId === $inflowId) {
                    throw new IntragroupDecisionException('Неверный идентификатор решения или операций.');
                }
                $head = $members[0];
                $existing = $this->decisions->findOneByIdAndCompanyId($id, $head);
                if (null !== $existing) {
                    if ($existing->getType() !== $type || $existing->getOutflowTransactionId() !== $outflowId || $existing->getInflowTransactionId() !== $inflowId) {
                        throw new IntragroupDecisionException('Идентификатор решения уже использован.');
                    }

                    return;
                }

                $legs = $this->transactions->lockedTransactions($members, [$outflowId, $inflowId]);
                $out = $legs[$outflowId] ?? null;
                $in = $legs[$inflowId] ?? null;
                if (null === $out || null === $in || CashDirection::OUTFLOW !== $out->direction || !$out->isReciprocalWith($in)) {
                    throw new IntragroupDecisionException('Выберите встречные выплату и поступление в RUB между двумя компаниями группы.');
                }
                if (!$out->amount->equals($in->amount)) {
                    throw new IntragroupDecisionException(sprintf('Суммы не совпадают: %s и %s. Пара возможна только при равных суммах', str_replace('.', ',', $out->amount->toDecimalString()), str_replace('.', ',', $in->amount->toDecimalString())));
                }
                if (IntragroupDecisionType::PAIR === $type) {
                    $this->assertLegsAvailable($head, $outflowId, $inflowId);
                } else {
                    if (!IntragroupMatcher::acceptsDates($out->occurredAt, $in->occurredAt)) {
                        throw new IntragroupDecisionException('Даты операций вне окна автоматического сопоставления.');
                    }
                    $current = ($this->reconcile)($members, $out->occurredAt, $in->occurredAt);
                    if (($current->pairs[$outflowId] ?? null) !== $inflowId || isset($current->manualDecisionIds[$outflowId])) {
                        throw new IntragroupDecisionException('Разъединить можно только действующую автоматическую пару.');
                    }
                }
                $this->em->persist(new CashIntragroupDecision($id, $head, $type, $outflowId, $inflowId, $userId, new \DateTimeImmutable()));
            });
        } catch (UniqueConstraintViolationException) {
            throw new IntragroupDecisionException('Идентификатор решения или выбранные операции уже использованы.');
        }
    }

    public function remove(string $activeCompanyId, string $userId, string $id): void
    {
        $this->em->wrapInTransaction(function () use ($activeCompanyId, $userId, $id): void {
            $members = $this->access->lockedMembers($activeCompanyId, $userId);
            if (!UuidV7::isValid($id)) {
                throw new IntragroupDecisionException('Неверный идентификатор решения.');
            }
            $decision = $this->decisions->findOneByIdAndCompanyId($id, $members[0]);
            if (null !== $decision) {
                $this->em->remove($decision);
            }
        });
    }

    private function assertLegsAvailable(string $head, string $outflowId, string $inflowId): void
    {
        foreach ($this->decisions->forGroup($head) as $decision) {
            if (IntragroupDecisionType::PAIR === $decision->getType()
                && [] !== array_intersect([$outflowId, $inflowId], [$decision->getOutflowTransactionId(), $decision->getInflowTransactionId()])) {
                throw new IntragroupDecisionException('Операция уже входит в ручную пару. Для нового сопоставления удалите прежнее решение.');
            }
        }
    }
}
