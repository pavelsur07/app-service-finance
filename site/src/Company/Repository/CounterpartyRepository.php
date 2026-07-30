<?php

declare(strict_types=1);

namespace App\Company\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Enum\CounterpartyType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Counterparty>
 */
class CounterpartyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Counterparty::class);
    }

    /**
     * Все контрагенты для пересчёта производных полей (CLI backfill, все компании).
     *
     * Намеренно без companyId — единственный кросс-компанийный метод репозитория.
     * Из HTTP-контекста вызывать нельзя: это обход изоляции по компании.
     *
     * @return iterable<Counterparty>
     */
    public function findAllForBackfill(): iterable
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->toIterable();
    }

    /**
     * Список для выбора в формах: архивные не предлагаются, но уже выбранное
     * значение остаётся в списке — иначе правка старой операции молча потеряет
     * ссылку на архивного контрагента.
     *
     * @return list<Counterparty>
     */
    public function findSelectableByCompany(string $companyId, ?string $keepId = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.company = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('c.name', 'ASC');

        if (null === $keepId) {
            $qb->andWhere('c.isArchived = false');
        } else {
            $qb->andWhere($qb->expr()->orX('c.isArchived = false', 'c.id = :keepId'))
                ->setParameter('keepId', $keepId);
        }

        /** @var list<Counterparty> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function findOneByInn(string $companyId, string $inn, ?string $exceptId = null): ?Counterparty
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.company = :companyId')
            ->setParameter('companyId', $companyId)
            ->andWhere('c.inn = :inn')
            ->setParameter('inn', $inn)
            ->setMaxResults(1);

        if (null !== $exceptId) {
            $qb->andWhere('c.id != :exceptId')->setParameter('exceptId', $exceptId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return Counterparty[]
     */
    public function findByFilters(Company $company, ?CounterpartyType $type, ?string $q, bool $showArchived, array $sort = ['name' => 'ASC']): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->setParameter('company', $company);

        if ($type) {
            $qb->andWhere('c.type = :type')->setParameter('type', $type);
        }

        if ($q) {
            $qb->andWhere('LOWER(c.name) LIKE :q OR c.inn LIKE :q')
               ->setParameter('q', '%'.strtolower($q).'%');
        }

        if (!$showArchived) {
            $qb->andWhere('c.isArchived = false');
        }

        foreach ($sort as $field => $direction) {
            $qb->addOrderBy('c.'.$field, $direction);
        }

        return $qb->getQuery()->getResult();
    }
}
