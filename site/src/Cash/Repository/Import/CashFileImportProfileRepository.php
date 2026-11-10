<?php

declare(strict_types=1);

namespace App\Cash\Repository\Import;

use App\Cash\Domain\Identifier\CanonicalUuid;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CashFileImportProfile>
 */
class CashFileImportProfileRepository extends ServiceEntityRepository
{
    public function listQuery(string $companyId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('profile')
            ->andWhere('IDENTITY(profile.company) = :company')->setParameter('company', $companyId)
            ->andWhere('profile.type = :type')->setParameter('type', CashFileImportProfile::TYPE_CASH_TRANSACTION)
            ->orderBy('profile.name', \SortDirection::Ascending)->addOrderBy('profile.id', \SortDirection::Ascending);
    }

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashFileImportProfile::class);
    }

    /**
     * Невалидный UUID — «не найдено», а не ошибка БД: id приходит из URL и формы.
     */
    public function findOneByIdAndCompanyId(string $id, string $companyId): ?CashFileImportProfile
    {
        if (!CanonicalUuid::isValid($id) || !CanonicalUuid::isValid($companyId)) {
            return null;
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('IDENTITY(p.company) = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return CashFileImportProfile[]
     */
    public function findByCompanyAndType(Company $company, string $type): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.company = :company')
            ->andWhere('p.type = :type')
            ->setParameter('company', $company)
            ->setParameter('type', $type)
            ->orderBy('p.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
