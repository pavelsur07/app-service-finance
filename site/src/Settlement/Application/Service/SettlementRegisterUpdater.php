<?php

declare(strict_types=1);

namespace App\Settlement\Application\Service;

use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Entity\SettlementItem;
use App\Settlement\Entity\SettlementMovement;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Repository\SettlementRepository;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SettlementRegisterUpdater
{
    public function __construct(private EntityManagerInterface $em, private SettlementRepository $repository)
    {
    }

    public function append(SettlementDocument $document, SettlementItem $item, Money $delta, ?SettlementBucket $bucket = null): void
    {
        if ($document->getCompanyId() !== $item->getCompanyId()) {
            throw new \DomainException('Компания позиции не совпадает.');
        }
        $this->em->persist(new SettlementMovement($document->getCompanyId(), $document->getId(), $item->getId(), $bucket ?? $item->getBucket(), $delta, $document->getEffectiveDate()));
    }

    /** Validate after the caller flushes, within the same transaction. */
    public function assertHistory(string $companyId, string $itemId): void
    {
        $balances = [];
        foreach ($this->repository->getHistory($companyId, $itemId) as $movement) {
            $bucket = $movement->getBucket()->value;
            $balances[$bucket] = ($balances[$bucket] ?? Money::fromMinor(0, 'RUB'))->add($movement->getDelta());
            if ($balances[$bucket]->isNegative()) {
                throw new \DomainException('Изменение создаёт отрицательный остаток в истории.');
            }
        }
    }

    /** @return array<string, Money> */
    public function balancesAt(string $companyId, string $itemId, \DateTimeImmutable $date): array
    {
        $balances = [];
        foreach (SettlementBucket::cases() as $bucket) {
            $balances[$bucket->value] = Money::fromMinor(0, 'RUB');
        }
        foreach ($this->repository->getHistory($companyId, $itemId) as $movement) {
            if ($movement->getEffectiveDate()->format('Y-m-d') > $date->format('Y-m-d')) {
                break;
            }
            $bucket = $movement->getBucket()->value;
            $balances[$bucket] = $balances[$bucket]->add($movement->getDelta());
        }

        return $balances;
    }
}
