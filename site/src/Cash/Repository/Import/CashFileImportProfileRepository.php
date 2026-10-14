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
