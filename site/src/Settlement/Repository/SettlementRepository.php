<?php

declare(strict_types=1);

namespace App\Settlement\Repository;

use App\Settlement\Entity\SettlementBook;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Entity\SettlementItem;
use App\Settlement\Entity\SettlementMovement;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SettlementRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function getDocumentForUpdate(string $companyId, string $id): SettlementDocument
    {
        $row = $this->em->getRepository(SettlementDocument::class)->findOneBy(['companyId' => $companyId, 'id' => $id]);
        if (null === $row) {
            throw new \DomainException('Документ не найден.');
        }
        $this->em->refresh($row, LockMode::PESSIMISTIC_WRITE);

        return $row;
    }

    public function getItemForUpdate(string $companyId, string $id): SettlementItem
    {
        $row = $this->em->getRepository(SettlementItem::class)->findOneBy(['companyId' => $companyId, 'id' => $id]);
        if (null === $row) {
            throw new \DomainException('Позиция не найдена.');
        }
        $this->em->refresh($row, LockMode::PESSIMISTIC_WRITE);

        return $row;
    }

    public function getBookForUpdate(string $companyId): SettlementBook
    {
        $book = $this->em->getRepository(SettlementBook::class)->findOneBy(['companyId' => $companyId]);
        if (null === $book) {
            throw new \DomainException('Настройте начало учёта.');
        }
        $this->em->refresh($book, LockMode::PESSIMISTIC_WRITE);

        return $book;
    }

    public function findByRequest(string $companyId, string $requestId): ?SettlementDocument
    {
        return $this->em->getRepository(SettlementDocument::class)->findOneBy(['companyId' => $companyId, 'requestId' => $requestId]);
    }

    public function findItemByDocument(string $companyId, string $documentId): ?SettlementItem
    {
        return $this->em->getRepository(SettlementItem::class)->findOneBy(['companyId' => $companyId, 'documentId' => $documentId]);
    }

    /** @return list<SettlementMovement> */
    public function getHistory(string $companyId, string $itemId): array
    {
        return $this->em->getRepository(SettlementMovement::class)->findBy(['companyId' => $companyId, 'itemId' => $itemId], ['effectiveDate' => 'ASC', 'id' => 'ASC']);
    }
}
