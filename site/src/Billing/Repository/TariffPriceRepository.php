<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\TariffPrice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TariffPrice>
 */
final class TariffPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TariffPrice::class);
    }

    /**
     * Цены справочника: код тарифа → срок в месяцах → копейки.
     *
     * @companyScopeExempt справочник цен общий для всех компаний
     *
     * @return array<string, array<int, int>>
     */
    public function amountsByTariffCode(): array
    {
        /** @var list<TariffPrice> $prices */
        $prices = $this->createQueryBuilder('p')
            ->addSelect('t')
            ->join('p.tariff', 't')
            ->orderBy('t.sortOrder', \SortDirection::Ascending)
            ->addOrderBy('p.months', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($prices as $price) {
            $result[$price->getTariff()->getCode()->value][$price->getMonths()] = $price->getAmountKopecks();
        }

        return $result;
    }
}
