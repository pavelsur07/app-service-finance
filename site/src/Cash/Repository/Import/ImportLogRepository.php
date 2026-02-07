<?php

namespace App\Cash\Repository\Import;

use App\Cash\Entity\Import\ImportLog;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;

/**
 * @extends ServiceEntityRepository<ImportLog>
 */
class ImportLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportLog::class);
    }

    /**
     * @param array{source?:string|null,dateFrom?:string|null,dateTo?:string|null} $filters
     */
    public function paginateByCompany(Company $company, int $page, int $perPage, array $filters = []): Pagerfanta
    {
        $qb = $this->createQueryBuilder('l')
            ->andWhere('l.company = :company')
            ->setParameter('company', $company)
            ->orderBy('l.startedAt', 'DESC');

        if (!empty($filters['source'])) {
            $qb->andWhere('l.source = :source')->setParameter('source', $filters['source']);
        }
        if (!empty($filters['dateFrom'])) {
            $qb->andWhere('l.startedAt >= :dateFrom')
                ->setParameter('dateFrom', new \DateTimeImmutable($filters['dateFrom'].' 00:00:00'));
        }
        if (!empty($filters['dateTo'])) {
            $qb->andWhere('l.startedAt <= :dateTo')
                ->setParameter('dateTo', new \DateTimeImmutable($filters['dateTo'].' 23:59:59'));
        }

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($perPage);
        $pager->setAllowOutOfRangePages(true);
        $pager->setCurrentPage($page);

        return $pager;
    }
}
