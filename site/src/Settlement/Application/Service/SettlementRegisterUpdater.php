<?php

declare(strict_types=1);

namespace App\Settlement\Application\Service;

use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Entity\SettlementItem;
use App\Settlement\Entity\SettlementMovement;
use App\Settlement\Repository\SettlementRepository;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SettlementRegisterUpdater
{
    public function __construct(private EntityManagerInterface $em, private SettlementRepository $repository)
    {
    }

    public function append(SettlementDocument $document, SettlementItem $item, Money $delta): void
    {
        if ($document->getCompanyId() !== $item->getCompanyId()) {
            throw new \DomainException('Компания позиции не совпадает.');
        }
        $this->em->persist(new SettlementMovement($document->getCompanyId(), $document->getId(), $item->getId(), $item->getBucket(), $delta, $document->getEffectiveDate()));
    }

    /** Validate after the caller flushes, within the same transaction. */
    public function assertHistory(string $companyId, string $itemId): void
    {
        $balance = Money::fromMinor(0, 'RUB');
        foreach ($this->repository->getHistory($companyId, $itemId) as $movement) {
            $balance = $balance->add($movement->getDelta());
            if ($balance->isNegative()) {
                throw new \DomainException('Изменение создаёт отрицательный остаток в истории.');
            }
        }
    }
}
