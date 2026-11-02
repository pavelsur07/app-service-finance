<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\BillingPayment;
use App\Billing\Enum\PaymentSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BillingPayment>
 */
final class BillingPaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingPayment::class);
    }

    public function findOneByIdAndCompanyId(string $id, string $companyId): ?BillingPayment
    {
        return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
    }

    public function findOneByExternalId(string $companyId, PaymentSource $source, string $externalId): ?BillingPayment
    {
        return $this->findOneBy(['companyId' => $companyId, 'source' => $source, 'externalId' => $externalId]);
    }

    /**
     * История оплат кабинета для Pagerfanta: новые сверху.
     */
    public function createByCompanyQueryBuilder(string $companyId): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->addSelect('t')
            ->join('p.tariff', 't')
            ->andWhere('p.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('p.receivedAt', \SortDirection::Descending)
            ->addOrderBy('p.id', \SortDirection::Descending);
    }
}
