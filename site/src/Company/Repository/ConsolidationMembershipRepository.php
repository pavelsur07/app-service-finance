<?php

declare(strict_types=1);

namespace App\Company\Repository;

use App\Company\Entity\ConsolidationMembership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<ConsolidationMembership>
 */
final class ConsolidationMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsolidationMembership::class);
    }

    public function findOneByCompany(string $companyId): ?ConsolidationMembership
    {
        Assert::uuid($companyId);

        return $this->find($companyId);
    }

    /**
     * Строки группы: головная первой, затем участники по id. Пусто — компания не головная.
     *
     * @return list<ConsolidationMembership>
     */
    public function findGroupOfHead(string $companyId): array
    {
        Assert::uuid($companyId);
        /** @var list<ConsolidationMembership> $rows */
        $rows = $this->createQueryBuilder('m')
            ->andWhere('m.headCompanyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('m.companyId', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
        $head = array_values(array_filter($rows, static fn (ConsolidationMembership $row): bool => $row->isHead()));
        if ([] === $head) {
            return [];
        }

        return [...$head, ...array_values(array_filter($rows, static fn (ConsolidationMembership $row): bool => !$row->isHead()))];
    }

    /**
     * Строки перечисленных компаний — для экрана настроек (в какой группе каждая).
     *
     * @companyScopeExempt список — компании кабинета владельца (findByUserId), каждая из них сама компания-скоуп
     *
     * @param list<string> $companyIds компании кабинета вызывающего
     *
     * @return array<string, ConsolidationMembership> companyId => строка
     */
    public function findByCompanies(array $companyIds): array
    {
        Assert::allUuid($companyIds);
        if ([] === $companyIds) {
            return [];
        }
        $result = [];
        foreach ($this->findBy(['companyId' => $companyIds]) as $row) {
            $result[$row->getCompanyId()] = $row;
        }

        return $result;
    }

    /**
     * Блокировка кабинета владельца до конца транзакции вызывающего: ею сериализуются изменение
     * состава групп и всё, что читает состав и пишет по нему (синхронизация дерева ДДС).
     */
    public function lockCabinet(string $ownerUserId): void
    {
        Assert::uuid($ownerUserId);
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
            ['key' => 'company_consolidation:'.$ownerUserId],
        );
    }

    /**
     * Под блокировкой кабинета владельца компании — головная её группы из БД (не из identity map).
     * Только внутри транзакции вызывающего.
     */
    public function lockedHeadOf(string $companyId): ?string
    {
        Assert::uuid($companyId);
        $connection = $this->getEntityManager()->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Состав группы под блокировкой читается только внутри транзакции.');
        }
        $owner = $connection->fetchOne('SELECT user_id FROM companies WHERE id = :companyId', ['companyId' => $companyId]);
        if (false === $owner || null === $owner) {
            return null;
        }
        $this->lockCabinet((string) $owner);
        $head = $connection->fetchOne('SELECT head_company_id FROM company_consolidation_member WHERE company_id = :companyId', ['companyId' => $companyId]);

        return false === $head ? null : (string) $head;
    }
}
