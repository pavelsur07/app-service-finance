<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\OzonTransactionTotalsCheck;
use App\Marketplace\Enum\OzonTransactionTotalsCheckStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

final class OzonTransactionTotalsCheckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonTransactionTotalsCheck::class);
    }

    public function save(OzonTransactionTotalsCheck $check): void
    {
        $this->getEntityManager()->persist($check);
    }

    public function findLatestByRawDocumentIdAndCompany(string $companyId, string $rawDocumentId): ?OzonTransactionTotalsCheck
    {
        Assert::uuid($companyId);
        Assert::uuid($rawDocumentId);

        return $this->createQueryBuilder('c')
            ->where('c.companyId = :companyId')
            ->andWhere('c.rawDocumentId = :rawDocumentId')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawDocumentId', $rawDocumentId)
            ->orderBy('c.checkedAt', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Возвращает только актуальные FAILED-проверки (latest by rawDocumentId).
     *
     * Исторический FAILED не возвращается, если по тому же rawDocumentId
     * существует более свежая проверка в любом другом статусе.
     *
     * @return list<OzonTransactionTotalsCheck>
     */
    public function findFailedByCompanyAndPeriod(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        Assert::uuid($companyId);

        $qb = $this->createQueryBuilder('c');

        $subQb = $this->createQueryBuilder('newer')
            ->select('1')
            ->where('newer.companyId = c.companyId')
            ->andWhere('newer.rawDocumentId = c.rawDocumentId')
            ->andWhere('(newer.checkedAt > c.checkedAt OR (newer.checkedAt = c.checkedAt AND newer.createdAt > c.createdAt))');

        return $qb
            ->where('c.companyId = :companyId')
            ->andWhere('c.status = :status')
            ->andWhere('c.periodFrom <= :to')
            ->andWhere('c.periodTo >= :from')
            ->andWhere($qb->expr()->not($qb->expr()->exists($subQb->getDQL())))
            ->setParameter('companyId', $companyId)
            ->setParameter('status', OzonTransactionTotalsCheckStatus::FAILED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('c.checkedAt', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
