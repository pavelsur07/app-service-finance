<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Document;
use App\Enum\DocumentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * @return Document[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.company = :company')
            ->setParameter('company', $company)
            ->orderBy('d.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Document[]
     */
    public function findByType(DocumentType $type, string $companyId, int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('IDENTITY(d.company) = :companyId')
            ->andWhere('d.type = :type')
            ->setParameter('companyId', $companyId)
            ->setParameter('type', $type->value)
            ->orderBy('d.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{count:int,sum:float}
     */
    public function aggregateByType(DocumentType $type, string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $result = $this->createQueryBuilder('d')
            ->select('COUNT(DISTINCT d.id) AS doc_count')
            ->addSelect('COALESCE(SUM(o.amount), 0) AS total_amount')
            ->leftJoin('d.operations', 'o')
            ->andWhere('IDENTITY(d.company) = :companyId')
            ->andWhere('d.type = :type')
            ->andWhere('d.date BETWEEN :from AND :to')
            ->setParameter('companyId', $companyId)
            ->setParameter('type', $type->value)
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $to, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) ($result['doc_count'] ?? 0),
            'sum' => (float) ($result['total_amount'] ?? 0.0),
        ];
    }
}
