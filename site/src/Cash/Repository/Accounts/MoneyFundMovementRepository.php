<?php

declare(strict_types=1);

namespace App\Cash\Repository\Accounts;

use App\Cash\Entity\Accounts\MoneyFund;
use App\Cash\Entity\Accounts\MoneyFundMovement;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MoneyFundMovement>
 */
class MoneyFundMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoneyFundMovement::class);
    }

    public function findOneByIdAndCompanyId(string $id, string $companyId): ?MoneyFundMovement
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id = :id')
            ->andWhere('IDENTITY(m.company) = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function maxUpdatedAtForCompany(Company $company): ?\DateTimeImmutable
    {
        $maxOccurredAt = $this->createQueryBuilder('m')
            ->select('MAX(m.occurredAt)')
            ->andWhere('m.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();

        if (!$maxOccurredAt instanceof \DateTimeInterface) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($maxOccurredAt);
    }

    public function deleteByFund(Company $company, MoneyFund $fund): void
    {
        $this->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.company = :company')
            ->andWhere('m.fund = :fund')
            ->setParameter('company', $company)
            ->setParameter('fund', $fund)
            ->getQuery()
            ->execute();
    }
}
