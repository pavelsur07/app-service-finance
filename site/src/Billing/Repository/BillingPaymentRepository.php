<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\BillingPayment;
use App\Billing\Enum\PaymentSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
