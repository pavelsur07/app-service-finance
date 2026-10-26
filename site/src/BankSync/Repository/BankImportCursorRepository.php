<?php

declare(strict_types=1);

namespace App\BankSync\Repository;

use App\BankSync\Entity\BankImportCursor;
use App\BankSync\Enum\BankCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BankImportCursor>
 */
final class BankImportCursorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankImportCursor::class);
    }

    public function findOneByAccount(string $companyId, BankCode $bankCode, string $accountNumber): ?BankImportCursor
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.companyId = :companyId')
            ->andWhere('c.bankCode = :bankCode')
            ->andWhere('c.accountNumber = :accountNumber')
            ->setParameter('companyId', $companyId)
            ->setParameter('bankCode', $bankCode->value)
            ->setParameter('accountNumber', $accountNumber)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Без flush(): новый курсор сохраняется в транзакции первого загруженного дня. Вставку
     * сериализует блокировка импорта счёта, которую держит вызывающий.
     */
    public function getOrCreate(string $companyId, BankCode $bankCode, string $accountNumber): BankImportCursor
    {
        $cursor = $this->findOneByAccount($companyId, $bankCode, $accountNumber);
        if (null === $cursor) {
            $cursor = new BankImportCursor($companyId, $bankCode, $accountNumber);
            $this->getEntityManager()->persist($cursor);
        }

        return $cursor;
    }
}
