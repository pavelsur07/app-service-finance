<?php

declare(strict_types=1);

namespace App\Settlement\Query;

use App\Settlement\Application\DTO\SettlementPositionRow;
use App\Settlement\Entity\SettlementAllocation;
use App\Settlement\Entity\SettlementBook;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Entity\SettlementItem;
use App\Settlement\Entity\SettlementMovement;
use App\Settlement\Entity\SettlementSourceLink;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Enum\SettlementSourceType;
use App\Shared\Domain\Exception\MoneyOverflowException;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class SettlementUiQuery
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function book(string $companyId): ?SettlementBook
    {
        return $this->em->getRepository(SettlementBook::class)->findOneBy(['companyId' => $companyId]);
    }

    public function document(string $companyId, string $id): ?SettlementDocument
    {
        return $this->em->getRepository(SettlementDocument::class)->findOneBy(['companyId' => $companyId, 'id' => $id]);
    }

    public function item(string $companyId, string $id): ?SettlementItem
    {
        return $this->em->getRepository(SettlementItem::class)->findOneBy(['companyId' => $companyId, 'id' => $id]);
    }

    public function reversal(string $companyId, string $id): ?SettlementDocument
    {
        return $this->em->getRepository(SettlementDocument::class)->findOneBy(['companyId' => $companyId, 'reversedDocumentId' => $id]);
    }

    public function allocation(string $companyId, string $id): ?SettlementAllocation
    {
        return $this->em->getRepository(SettlementAllocation::class)->findOneBy(['companyId' => $companyId, 'id' => $id]);
    }

    public function allocationByDocument(string $companyId, string $id): ?SettlementAllocation
    {
        return $this->em->getRepository(SettlementAllocation::class)->findOneBy(['companyId' => $companyId, 'documentId' => $id]);
    }

    public function sourceLink(string $companyId, SettlementSourceType $type, string $id): ?SettlementSourceLink
    {
        return $this->em->getRepository(SettlementSourceLink::class)->findOneBy(['companyId' => $companyId, 'sourceType' => $type, 'sourceId' => $id]);
    }

    public function documents(string $companyId, ?string $counterpartyId = null): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->select('d')->from(SettlementDocument::class, 'd')->where('d.companyId=:company')->setParameter('company', $companyId)->orderBy('d.effectiveDate', \SortDirection::Descending)->addOrderBy('d.eventSequence', \SortDirection::Descending)->addOrderBy('d.id', \SortDirection::Descending);
        if (null !== $counterpartyId) {
            $qb->andWhere('d.counterpartyId=:party')->setParameter('party', $counterpartyId);
        }

        return $qb;
    }

    /** @return list<SettlementMovement> */
    public function documentMovements(string $companyId, string $id): array
    {
        return $this->em->getRepository(SettlementMovement::class)->findBy(['companyId' => $companyId, 'documentId' => $id], ['itemId' => 'ASC', 'bucket' => 'ASC']);
    }

    /** @return list<SettlementPositionRow> */
    public function positions(string $companyId, string $counterpartyId, ?string $documentId = null): array
    {
        $where = '';
        $parameters = [$companyId, $counterpartyId];
        if (null !== $documentId) {
            $where = ' AND i.document_id=?';
            $parameters[] = $documentId;
        }
        $rows = $this->em->getConnection()->fetchAllAssociative("SELECT i.id,i.document_id,i.counterparty_id,i.bucket,i.effective_date,d.kind,d.event_sequence,COALESCE(SUM(m.delta_amount_minor),0)::text AS available FROM settlement_items i JOIN settlement_documents d ON d.company_id=i.company_id AND d.id=i.document_id LEFT JOIN settlement_movements m ON m.company_id=i.company_id AND m.item_id=i.id AND m.delta_currency='RUB' WHERE i.company_id=? AND i.counterparty_id=? AND i.amount_currency='RUB' AND NOT EXISTS (SELECT 1 FROM settlement_documents r WHERE r.company_id=i.company_id AND r.reversed_document_id=d.id)".$where.' GROUP BY i.id,i.document_id,i.counterparty_id,i.bucket,i.effective_date,d.kind,d.event_sequence ORDER BY d.event_sequence,i.id', $parameters);
        $positions = [];
        foreach ($rows as $row) {
            $minor = filter_var($row['available'], \FILTER_VALIDATE_INT);
            if (false === $minor) {
                throw new MoneyOverflowException('Остаток превышает допустимую сумму.');
            }
            $positions[] = new SettlementPositionRow($row['id'], $row['document_id'], (int) $row['event_sequence'], $row['counterparty_id'], SettlementBucket::from($row['bucket']), SettlementDocumentKind::from($row['kind']), Money::fromMinor($minor, 'RUB'), new \DateTimeImmutable($row['effective_date']));
        }

        return $positions;
    }

    /** @param list<string> $ids
     * @return array<string,string> original ID => reversal ID
     */
    public function reversalIds(string $companyId, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT reversed_document_id,id FROM settlement_documents WHERE company_id=? AND reversed_document_id IN (?)', [$companyId, $ids], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING]);

        return array_column($rows, 'id', 'reversed_document_id');
    }
}
