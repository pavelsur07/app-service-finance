<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\BillingCheckout;
use App\Billing\Entity\BillingPayment;
use App\Billing\Enum\CheckoutStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BillingCheckout>
 */
final class BillingCheckoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingCheckout::class);
    }

    public function findOneByIdAndCompanyId(string $id, string $companyId): ?BillingCheckout
    {
        return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
    }

    /**
     * @companyScopeExempt уведомление ЮKassa приходит без сессии: платёж ищется по ID провайдера, компания сверяется с metadata после GET из ЮKassa
     */
    public function findOneByProviderPaymentId(string $providerPaymentId): ?BillingCheckout
    {
        return $this->findOneBy(['providerPaymentId' => $providerPaymentId]);
    }

    /**
     * Незавершённые попытки с платежом ЮKassa, созданные в окне — для cron-сверки.
     *
     * @companyScopeExempt обход всех кабинетов cron-командой сверки ЮKassa; ограничение — статус и окно времени
     *
     * @return list<BillingCheckout>
     */
    public function findPendingWithProviderPayment(\DateTimeImmutable $createdAfter, \DateTimeImmutable $createdBefore, int $limit): array
    {
        /** @var list<BillingCheckout> $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.status = :status')
            ->andWhere('c.providerPaymentId IS NOT NULL')
            ->andWhere('c.createdAt >= :after')
            ->andWhere('c.createdAt < :before')
            ->setParameter('status', CheckoutStatus::PENDING)
            ->setParameter('after', $createdAfter)
            ->setParameter('before', $createdBefore)
            ->orderBy('c.createdAt', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * `failed_to_apply`, ещё не разобранные: администратор не отметил оплату с ID платежа ЮKassa
     * в номере (любым способом).
     *
     * @return list<BillingCheckout>
     */
    public function findFailedToApplyByCompanyId(string $companyId): array
    {
        /** @var list<BillingCheckout> $rows */
        $rows = $this->createQueryBuilder('c')
            ->addSelect('t')
            ->join('c.tariff', 't')
            ->andWhere('c.companyId = :companyId')
            ->andWhere('c.status = :status')
            ->andWhere('NOT EXISTS (SELECT p.id FROM '.BillingPayment::class.' p WHERE p.companyId = c.companyId AND p.externalId = c.providerPaymentId)')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', CheckoutStatus::FAILED_TO_APPLY)
            ->orderBy('c.createdAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
