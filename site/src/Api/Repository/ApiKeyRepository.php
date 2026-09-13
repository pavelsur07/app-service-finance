<?php

declare(strict_types=1);

namespace App\Api\Repository;

use App\Api\Entity\ApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

/** @extends ServiceEntityRepository<ApiKey> */
final class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    /** @companyScopeExempt Authentication bootstrap only: caller must verify secret then bind company before accessing data. */
    public function findOneByPublicIdentifier(string $publicIdentifier): ?ApiKey
    {
        return $this->createQueryBuilder('k')->andWhere('k.publicIdentifier = :identifier')
            ->setParameter('identifier', $publicIdentifier)->getQuery()->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }

    public function findOneByIdAndCompany(string $id, string $companyId, bool $forUpdate = false): ?ApiKey
    {
        if (!Uuid::isValid($id) || !Uuid::isValid($companyId)) {
            return null;
        }

        $query = $this->createListQueryBuilder($companyId)->andWhere('k.id = :id')->setParameter('id', $id)->getQuery();
        if ($forUpdate) {
            // Re-read the row after acquiring its lock, including an already managed entity.
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true);
        }

        return $query->getOneOrNullResult();
    }

    public function createListQueryBuilder(string $companyId): QueryBuilder
    {
        return $this->createQueryBuilder('k')->andWhere('k.companyId = :companyId')->setParameter('companyId', $companyId)->orderBy('k.createdAt', 'DESC')->addOrderBy('k.id', 'DESC');
    }
}
