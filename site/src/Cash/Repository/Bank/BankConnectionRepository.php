<?php

declare(strict_types=1);

namespace App\Cash\Repository\Bank;

use App\Cash\Domain\Identifier\CanonicalUuid;
use App\Cash\Entity\Bank\BankConnection;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BankConnection>
 */
class BankConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankConnection::class);
    }

    /**
     * Невалидный UUID — «не найдено», а не ошибка БД: id приходит из URL и формы.
     */
    public function findOneByIdAndCompanyId(string $id, string $companyId): ?BankConnection
    {
        if (!CanonicalUuid::isValid($id) || !CanonicalUuid::isValid($companyId)) {
            return null;
        }

        return $this->createQueryBuilder('bc')
            ->andWhere('bc.id = :id')
            ->andWhere('IDENTITY(bc.company) = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @companyScopeExempt CLI `BankEnqueueImportCommand` ставит импорт по подключениям всех компаний; каждое сообщение дальше несёт companyId
     *
     * @return BankConnection[]
     */
    public function findActiveByBankCode(string $bankCode): array
    {
        return $this->createQueryBuilder('bc')
            ->andWhere('bc.bankCode = :bankCode')
            ->andWhere('bc.isActive = :isActive')
            ->setParameter('bankCode', $bankCode)
            ->setParameter('isActive', true)
            ->getQuery()
            ->getResult();
    }

    public function findActiveByCompanyAndBankCode(Company $company, string $bankCode): ?BankConnection
    {
        return $this->createQueryBuilder('bc')
            ->andWhere('bc.company = :company')
            ->andWhere('bc.bankCode = :bankCode')
            ->andWhere('bc.isActive = :isActive')
            ->setParameter('company', $company)
            ->setParameter('bankCode', $bankCode)
            ->setParameter('isActive', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
