<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\CompanySubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanySubscription>
 */
final class CompanySubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanySubscription::class);
    }

    public function findOneByCompanyId(string $companyId): ?CompanySubscription
    {
        return $this->findOneBy(['companyId' => $companyId]);
    }

    /**
     * Подписки кабинетов одним запросом (с тарифом), ключ — companyId.
     *
     * @companyScopeExempt выборка по явному списку кабинетов (админка, страница пользователей): ограничение — сам список
     *
     * @param list<string> $companyIds
     *
     * @return array<string, CompanySubscription>
     */
    public function findByCompanyIds(array $companyIds): array
    {
        if ([] === $companyIds) {
            return [];
        }

        /** @var list<CompanySubscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->addSelect('t')
            ->join('s.tariff', 't')
            ->andWhere('s.companyId IN (:companyIds)')
            ->setParameter('companyIds', array_values(array_unique($companyIds)))
            ->getQuery()
            ->getResult();

        $byCompany = [];
        foreach ($rows as $subscription) {
            $byCompany[$subscription->getCompanyId()] = $subscription;
        }

        return $byCompany;
    }

    /**
     * Подписки с датой окончания, у которых «оплачено по» — одна из дат (cron напоминаний).
     *
     * @companyScopeExempt обход всех кабинетов cron-командой напоминаний; ограничение — список дат окончания
     *
     * @param list<\DateTimeImmutable> $paidUntilDates
     *
     * @return list<CompanySubscription>
     */
    public function findEndingOn(array $paidUntilDates): array
    {
        if ([] === $paidUntilDates) {
            return [];
        }

        /** @var list<CompanySubscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->addSelect('t')
            ->join('s.tariff', 't')
            ->andWhere('s.paidUntil IN (:dates)')
            ->setParameter('dates', array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $paidUntilDates))
            ->orderBy('s.paidUntil')
            ->addOrderBy('s.companyId')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
