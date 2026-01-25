<?php

namespace App\Cash\Repository\Bank;

use App\Cash\Entity\Bank\BankImportCursor;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

/**
 * @extends ServiceEntityRepository<BankImportCursor>
 */
class BankImportCursorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankImportCursor::class);
    }

    public function findOneByCompanyBankAndAccount(Company $company, string $bankCode, string $accountNumber): ?BankImportCursor
    {
        return $this->createQueryBuilder('bic')
            ->andWhere('bic.company = :company')
            ->andWhere('bic.bankCode = :bankCode')
            ->andWhere('bic.accountNumber = :accountNumber')
            ->setParameter('company', $company)
            ->setParameter('bankCode', $bankCode)
            ->setParameter('accountNumber', $accountNumber)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getOrCreate(Company $company, string $bankCode, string $accountNumber): BankImportCursor
    {
        $cursor = $this->findOneByCompanyBankAndAccount($company, $bankCode, $accountNumber);
        if ($cursor) {
            return $cursor;
        }

        $cursor = new BankImportCursor(Uuid::uuid4()->toString(), $company, $bankCode, $accountNumber);
        $this->getEntityManager()->persist($cursor);
        $this->getEntityManager()->flush();

        return $cursor;
    }

    public function save(BankImportCursor $cursor): void
    {
        $this->getEntityManager()->persist($cursor);
        $this->getEntityManager()->flush();
    }
}
