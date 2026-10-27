<?php

declare(strict_types=1);

namespace App\BankSync\Repository;

use App\BankSync\Entity\BankAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

/**
 * @extends ServiceEntityRepository<BankAccount>
 */
final class BankAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankAccount::class);
    }

    /**
     * Невалидный UUID — «не найдено»: id приходит из URL.
     */
    public function findOneByIdAndCompanyId(string $id, string $companyId): ?BankAccount
    {
        if (!Uuid::isValid($id) || !Uuid::isValid($companyId)) {
            return null;
        }

        return $this->createQueryBuilder('a')
            ->andWhere('a.id = :id')
            ->andWhere('a.companyId = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Счета подключения по номеру счёта, для страницы и каталога.
     *
     * @return list<BankAccount>
     */
    public function findByConnection(string $companyId, string $connectionId): array
    {
        if (!Uuid::isValid($companyId) || !Uuid::isValid($connectionId)) {
            return [];
        }

        /* @var list<BankAccount> */
        return $this->createQueryBuilder('a')
            ->andWhere('a.companyId = :companyId')
            ->andWhere('a.connectionId = :connectionId')
            ->setParameter('companyId', $companyId)
            ->setParameter('connectionId', $connectionId)
            ->orderBy('a.accountNumber', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Счёт банка компании, к которому привязан денежный счёт (их не больше одного —
     * частичный unique).
     */
    public function findOneByMoneyAccount(string $companyId, string $moneyAccountId): ?BankAccount
    {
        if (!Uuid::isValid($companyId) || !Uuid::isValid($moneyAccountId)) {
            return null;
        }

        return $this->createQueryBuilder('a')
            ->andWhere('a.companyId = :companyId')
            ->andWhere('a.moneyAccountId = :moneyAccountId')
            ->setParameter('companyId', $companyId)
            ->setParameter('moneyAccountId', $moneyAccountId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Денежные счета компании, уже привязанные к какому-либо счёту банка.
     *
     * @return list<string>
     */
    public function findLinkedMoneyAccountIds(string $companyId): array
    {
        if (!Uuid::isValid($companyId)) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->createQueryBuilder('a')
            ->select('a.moneyAccountId')
            ->andWhere('a.companyId = :companyId')
            ->andWhere('a.moneyAccountId IS NOT NULL')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getSingleColumnResult();

        return $ids;
    }

    /**
     * Число непривязанных счетов по подключениям компании — для списка подключений.
     *
     * @return array<string, int> connectionId => число
     */
    public function countUnlinkedByConnection(string $companyId): array
    {
        if (!Uuid::isValid($companyId)) {
            return [];
        }

        /** @var list<array{connectionId: string, unlinked: int|string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.connectionId', 'COUNT(a.id) AS unlinked')
            ->andWhere('a.companyId = :companyId')
            ->andWhere('a.moneyAccountId IS NULL')
            ->andWhere('a.presentInBank = true')
            ->setParameter('companyId', $companyId)
            ->groupBy('a.connectionId')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['connectionId']] = (int) $row['unlinked'];
        }

        return $counts;
    }

    /**
     * Денежный счёт, в который сейчас грузится счёт банка, — под `FOR UPDATE` строки счёта
     * банка: привязка, отвязка и удаление подключения ждут commit дня. null — счёт банка
     * удалён, отвязан, закрыт в банке или пропал из ответа. Только внутри транзакции.
     */
    public function lockLoadableMoneyAccountId(string $companyId, string $bankAccountId): ?string
    {
        if (!Uuid::isValid($companyId) || !Uuid::isValid($bankAccountId)) {
            return null;
        }

        $moneyAccountId = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT money_account_id FROM bank_sync_account
             WHERE id = :id AND company_id = :companyId AND enabled_in_bank AND present_in_bank
             FOR UPDATE',
            ['id' => $bankAccountId, 'companyId' => $companyId],
        );

        return \is_string($moneyAccountId) ? $moneyAccountId : null;
    }

    /**
     * Только persist(): коммитит точка входа.
     */
    public function add(BankAccount $account): void
    {
        $this->getEntityManager()->persist($account);
    }
}
