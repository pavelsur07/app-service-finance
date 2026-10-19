<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Application\DTO\ListingsFilter;
use App\Marketplace\Entity\MarketplaceListing;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;

final class MarketplaceListingsQuery
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ListingTagAssignmentRepository $tagAssignments,
    ) {
    }

    /**
     * @return Pagerfanta<MarketplaceListing>
     */
    public function paginate(string $companyId, ListingsFilter $filter, int $page, int $perPage = 25): Pagerfanta
    {
        $qb = $this->filteredQueryBuilder($companyId, $filter)
            ->addSelect('p')
            ->leftJoin('l.product', 'p');

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage(max(1, $page));

        return $pager;
    }

    /**
     * Id листингов под фильтром — для назначения тега по фильтру. Те же условия
     * и порядок, что у списка: назначается ровно то, что показано.
     *
     * @return list<string>
     */
    public function matchingIds(string $companyId, ListingsFilter $filter, int $limit): array
    {
        /** @var list<string> $ids */
        $ids = $this->filteredQueryBuilder($companyId, $filter)
            ->select('l.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return $ids;
    }

    private function filteredQueryBuilder(string $companyId, ListingsFilter $filter): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->select('l')
            ->from(MarketplaceListing::class, 'l')
            ->where('IDENTITY(l.company) = :companyId')
            // id — второй ключ: created_at хранит секунды, а у листингов одной
            // загрузки он совпадает, и без него страницы перемешиваются.
            ->orderBy('l.createdAt', \SortDirection::Descending)
            ->addOrderBy('l.id', \SortDirection::Descending)
            ->setParameter('companyId', $companyId);

        if (true === $filter->mapped) {
            $qb->andWhere('l.product IS NOT NULL');
        } elseif (false === $filter->mapped) {
            $qb->andWhere('l.product IS NULL');
        }

        if (null !== $filter->marketplace) {
            $qb->andWhere('l.marketplace = :marketplace')
                ->setParameter('marketplace', $filter->marketplace);
        }

        if (null !== $filter->search) {
            // mb_strtolower: LOWER() в Postgres понижает кириллицу, strtolower — нет.
            // Экранирование: % и _ из запроса ищутся буквально (обратный слеш — escape LIKE в Postgres по умолчанию).
            $qb->andWhere('LOWER(l.name) LIKE :search OR LOWER(l.supplierSku) LIKE :search OR LOWER(l.marketplaceSku) LIKE :search')
                ->setParameter('search', '%'.addcslashes(mb_strtolower($filter->search), '%_\\').'%');
        }

        // Таблица связей с тегами не замаплена в ORM — DQL её не видит, поэтому id.
        if (null !== $filter->tagId) {
            $tagged = $this->tagAssignments->listingIdsByTags($companyId, [$filter->tagId], false);
            if ([] === $tagged) {
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere('l.id IN (:taggedIds)')->setParameter('taggedIds', $tagged);
            }
        } elseif ($filter->withoutTags) {
            $tagged = $this->tagAssignments->taggedListingIds($companyId);
            if ([] !== $tagged) {
                $qb->andWhere('l.id NOT IN (:taggedIds)')->setParameter('taggedIds', $tagged);
            }
        }

        return $qb;
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
