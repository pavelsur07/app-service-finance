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
}
