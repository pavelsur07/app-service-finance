<?php

namespace App\Company\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\Company\Security\SystemCompanyRoles;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanyRole>
 */
class CompanyRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyRole::class);
    }

    public function createAssignableForCompanyQueryBuilder(Company $company): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.company IS NULL OR r.company = :company')
            ->andWhere('r.id != :ownerId')
            ->setParameter('company', $company)
            ->setParameter('ownerId', SystemCompanyRoles::OWNER_ID)
            ->orderBy('r.name', 'ASC');
    }

    /**
     * @return list<CompanyRole>
     */
    public function findAssignableForCompany(Company $company): array
    {
        return $this->createAssignableForCompanyQueryBuilder($company)
            ->getQuery()
            ->getResult();
    }

    /**
     * Шаблон компании с таким именем, кроме самого редактируемого.
     * Системные шаблоны (`company IS NULL`) в проверку не попадают: их имена
     * фиксированы seed'ом, а компания их не редактирует.
     *
     * Сравнение регистронезависимое — намеренно строже, чем частичный unique index
     * `uniq_company_role_company_name` (он точный). Направление безопасное: приложение
     * отклоняет надмножество того, что отвергнет БД, поэтому 500 из-за ограничения
     * не проскочит, а «Финансист»/«финансист» не расходятся визуально в списке.
     */
    public function findOneByCompanyAndName(Company $company, string $name, ?string $exceptRoleId = null): ?CompanyRole
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.company = :company')
            ->andWhere('LOWER(r.name) = LOWER(:name)')
            ->setParameter('company', $company)
            ->setParameter('name', $name)
            ->setMaxResults(1);

        if (null !== $exceptRoleId) {
            $qb->andWhere('r.id != :exceptId')->setParameter('exceptId', $exceptRoleId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
