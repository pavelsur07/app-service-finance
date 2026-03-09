<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\ORM\EntityManagerInterface;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;

final class MarketplaceListingsQuery
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return Pagerfanta<MarketplaceListing>
     */
    public function paginate(
        string $companyId,
        ?bool $mapped,
        int $page,
        int $perPage = 25,
        ?MarketplaceType $marketplace = null,
    ): Pagerfanta {
        $qb = $this->em->createQueryBuilder()
            ->select('l', 'p')
            ->from(MarketplaceListing::class, 'l')
            ->leftJoin('l.product', 'p')
            ->where('IDENTITY(l.company) = :companyId')
            ->orderBy('l.createdAt', 'DESC')
            ->setParameter('companyId', $companyId);

        if ($mapped === true) {
            $qb->andWhere('l.product IS NOT NULL');
        } elseif ($mapped === false) {
            $qb->andWhere('l.product IS NULL');
        }

        if ($marketplace !== null) {
            $qb->andWhere('l.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage(max(1, $page));

        return $pager;
    }

    public function countAll(string $companyId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(MarketplaceListing::class, 'l')
            ->where('IDENTITY(l.company) = :companyId')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countMapped(string $companyId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(MarketplaceListing::class, 'l')
            ->where('IDENTITY(l.company) = :companyId')
            ->andWhere('l.product IS NOT NULL')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnmapped(string $companyId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(MarketplaceListing::class, 'l')
            ->where('IDENTITY(l.company) = :companyId')
            ->andWhere('l.product IS NULL')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
