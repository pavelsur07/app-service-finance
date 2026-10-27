<?php

declare(strict_types=1);

namespace App\BankSync\Repository;

use App\BankSync\Entity\BankConnection;
use App\BankSync\Enum\BankCode;
use App\BankSync\Enum\BankConnectionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

/**
 * @extends ServiceEntityRepository<BankConnection>
 */
final class BankConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankConnection::class);
    }

    /**
     * Невалидный UUID — «не найдено», а не ошибка БД: id приходит из URL и сообщения.
     */
    public function findOneByIdAndCompanyId(string $id, string $companyId): ?BankConnection
    {
        if (!Uuid::isValid($id) || !Uuid::isValid($companyId)) {
            return null;
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.companyId = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<BankConnection>
     */
    public function findByCompanyId(string $companyId): array
    {
        if (!Uuid::isValid($companyId)) {
            return [];
        }

        /* @var list<BankConnection> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('c.createdAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @companyScopeExempt CLI `bank-sync:enqueue` ставит загрузку по подключениям всех компаний; каждое сообщение дальше несёт companyId
     *
     * @return list<BankConnection>
     */
    public function findSyncableByBankCode(BankCode $bankCode): array
    {
        /* @var list<BankConnection> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.bankCode = :bankCode')
            ->andWhere('c.status = :status')
            ->setParameter('status', BankConnectionStatus::ACTIVE->value)
            ->setParameter('bankCode', $bankCode->value)
            ->orderBy('c.companyId', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Подключения среды банка, у которых известен пользователь банка и есть что сверять
     * (отозванные ждут нового входа).
     *
     * @companyScopeExempt CLI `bank-sync:alfa:consents-sync` сверяет согласия всех компаний одним ответом банка; меняет только срок и статус найденных подключений
     *
     * @return list<BankConnection>
     */
    public function findWithExternalSubjectByBankAndBaseUrl(BankCode $bankCode, string $baseUrl): array
    {
        /* @var list<BankConnection> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.bankCode = :bankCode')
            ->andWhere('c.baseUrl = :baseUrl')
            ->andWhere('c.externalSubject IS NOT NULL')
            ->andWhere('c.status <> :revoked')
            ->setParameter('bankCode', $bankCode->value)
            ->setParameter('baseUrl', $baseUrl)
            ->setParameter('revoked', BankConnectionStatus::REVOKED->value)
            ->orderBy('c.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Только persist()/remove(): коммитит точка входа.
     */
    public function add(BankConnection $connection): void
    {
        $this->getEntityManager()->persist($connection);
    }

    public function remove(BankConnection $connection): void
    {
        $this->getEntityManager()->remove($connection);
    }
}
