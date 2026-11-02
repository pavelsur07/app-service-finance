<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\Tariff;
use App\Billing\Enum\TariffCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tariff>
 */
final class TariffRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tariff::class);
    }

    /**
     * @companyScopeExempt справочник тарифов общий для всех компаний
     */
    public function findOneByCode(TariffCode $code): ?Tariff
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * @companyScopeExempt справочник тарифов общий для всех компаний
     *
     * @return list<Tariff>
     */
    public function findActiveOrdered(): array
    {
        /** @var list<Tariff> $tariffs */
        $tariffs = $this->createQueryBuilder('t')
            ->andWhere('t.active = true')
            ->orderBy('t.sortOrder', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $tariffs;
    }
}
