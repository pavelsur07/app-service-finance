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
     * Новый курсор не передаётся в persist(): его сохраняет `add()` в транзакции первого
     * загруженного дня. Иначе сбой HTTP до неё оставил бы курсор в UnitOfWork, и коммит
     * журнала после сбоя вставил бы его уже без блокировки счёта.
     */
    public function findOrNew(string $companyId, BankCode $bankCode, string $accountNumber): BankImportCursor
    {
        return $this->findOneByAccount($companyId, $bankCode, $accountNumber)
            ?? new BankImportCursor($companyId, $bankCode, $accountNumber);
    }

    /**
     * Только persist(): коммитит точка входа.
     */
    public function add(BankImportCursor $cursor): void
    {
        $this->getEntityManager()->persist($cursor);
    }
}
