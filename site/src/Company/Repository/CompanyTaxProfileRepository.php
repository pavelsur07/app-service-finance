<?php

declare(strict_types=1);

namespace App\Company\Repository;

use App\Company\Entity\CompanyTaxProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanyTaxProfile>
 */
final class CompanyTaxProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyTaxProfile::class);
    }

    public function findOneByCompanyAndValidFrom(string $companyId, ?\DateTimeImmutable $validFrom): ?CompanyTaxProfile
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->setMaxResults(1);

        if (null === $validFrom) {
            $qb->andWhere('p.validFrom IS NULL');
        } else {
            $qb->andWhere('p.validFrom = :validFrom')->setParameter('validFrom', $validFrom, Types::DATE_IMMUTABLE);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findOneByIdAndCompany(string $id, string $companyId): ?CompanyTaxProfile
    {
        return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
    }

    /**
     * Периоды компании по порядку действия: начальный первым, затем по дате начала.
     *
     * @return list<CompanyTaxProfile>
     */
    public function findByCompany(string $companyId): array
    {
        /** @var list<CompanyTaxProfile> $profiles */
        $profiles = $this->createQueryBuilder('p')
            ->addSelect('CASE WHEN p.validFrom IS NULL THEN 0 ELSE 1 END AS HIDDEN dated')
            ->andWhere('p.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('dated', \SortDirection::Ascending)
            ->addOrderBy('p.validFrom', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $profiles;
    }

    /**
     * Профиль, действующий на календарную дату: последний датированный с началом не позже даты,
     * иначе начальный.
     */
    public function findEffectiveOn(string $companyId, \DateTimeImmutable $date): ?CompanyTaxProfile
    {
        return $this->effectiveProfilesOn($date)
            ->addSelect('CASE WHEN p.validFrom IS NULL THEN 0 ELSE 1 END AS HIDDEN dated')
            ->andWhere('p.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('dated', \SortDirection::Descending)
            ->addOrderBy('p.validFrom', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<string> $companyIds
     *
     * @return array<string, CompanyTaxProfile>
     *
     * @companyScopeExempt выборка ограничена переданным списком идентификаторов компаний владельца
     */
    public function findEffectiveForCompanies(array $companyIds, \DateTimeImmutable $date): array
    {
        if ([] === $companyIds) {
            return [];
        }

        $newerProfile = $this->createQueryBuilder('newer')
            ->select('1')
            ->where('newer.companyId = p.companyId')
            ->andWhere('newer.validFrom <= :day')
            ->andWhere('p.validFrom IS NULL OR newer.validFrom > p.validFrom');

        /** @var array<string, CompanyTaxProfile> $profiles */
        $profiles = $this->effectiveProfilesOn($date)
            ->indexBy('p', 'p.companyId')
            ->andWhere('p.companyId IN (:companyIds)')
            ->andWhere('NOT EXISTS ('.$newerProfile->getDQL().')')
            ->setParameter('companyIds', $companyIds)
            ->getQuery()
            ->getResult();

        return $profiles;
    }

    private function effectiveProfilesOn(\DateTimeImmutable $date): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.validFrom IS NULL OR p.validFrom <= :day')
            ->setParameter('day', new \DateTimeImmutable($date->format('Y-m-d')), Types::DATE_IMMUTABLE);
    }
}
