<?php

namespace App\Cash\Repository\Accounts;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Entity\Company;
use App\Enum\MoneyAccountType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<MoneyAccount>
 */
class MoneyAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoneyAccount::class);
    }

    /**
     * @return MoneyAccount[]
     */
    public function findByFilters(Company $company, ?MoneyAccountType $type, ?array $currencies, ?bool $active, ?string $q, array $sort): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.company = :company')
            ->setParameter('company', $company);

        if ($type) {
            $qb->andWhere('m.type = :type')->setParameter('type', $type);
        }

        if ($currencies) {
            $qb->andWhere('m.currency IN (:currencies)')->setParameter('currencies', $currencies);
        }

        if (null !== $active) {
            $qb->andWhere('m.isActive = :active')->setParameter('active', $active);
        }

        if ($q) {
            $qb->andWhere('LOWER(m.name) LIKE :q OR LOWER(m.bankName) LIKE :q OR LOWER(m.accountNumber) LIKE :q OR LOWER(m.walletId) LIKE :q')
               ->setParameter('q', '%'.strtolower($q).'%');
        }

        foreach ($sort as $field => $direction) {
            $qb->addOrderBy('m.'.$field, $direction);
        }

        return $qb->getQuery()->getResult();
    }

    public function unsetDefaultForCurrency(Company $company, string $currency, ?UuidInterface $exceptId = null): void
    {
        $qb = $this->createQueryBuilder('m')
            ->update()
            ->set('m.isDefault', ':false')
            ->andWhere('m.company = :company')
            ->andWhere('m.currency = :currency')
            ->setParameter('false', false)
            ->setParameter('company', $company)
            ->setParameter('currency', strtoupper($currency));

        if ($exceptId) {
            $qb->andWhere('m.id != :except')->setParameter('except', $exceptId->toString());
        }

        $qb->getQuery()->execute();
    }

    public function findOneByNormalizedAccountNumber(Company $company, string $normalized): ?MoneyAccount
    {
        $normalized = preg_replace('/\D+/', '', $normalized);
        if ('' === $normalized) {
            return null;
        }

        $accounts = $this->createQueryBuilder('m')
            ->andWhere('m.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getResult();

        foreach ($accounts as $account) {
            if ($account instanceof MoneyAccount) {
                $accountNumber = preg_replace('/\D+/', '', (string) $account->getAccountNumber());
                if ($accountNumber === $normalized && $account->isActive()) {
                    return $account;
                }
            }
        }

        return null;
    }

    public function findOneByCompanyAndAccountNumber(Company $company, string $accountNumber): ?MoneyAccount
    {
        $normalized = preg_replace('/\D+/', '', $accountNumber);
        if ('' === $normalized) {
            return null;
        }

        return $this->findOneByNormalizedAccountNumber($company, $normalized);
    }
}
