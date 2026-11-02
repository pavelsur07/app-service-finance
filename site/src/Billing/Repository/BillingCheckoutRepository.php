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
     * Незавершённые попытки с платежом ЮKassa, созданные раньше `$createdBefore`; давно не
     * проверенные первыми (`updated_at` двигает каждая проверка) — вечные `pending` не занимают окно.
     *
     * @companyScopeExempt обход всех кабинетов cron-командой сверки ЮKassa; ограничение — статус pending
     *
     * @return list<BillingCheckout>
     */
    public function findPendingWithProviderPayment(\DateTimeImmutable $createdBefore, int $limit): array
    {
        /** @var list<BillingCheckout> $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.status = :status')
            ->andWhere('c.providerPaymentId IS NOT NULL')
            ->andWhere('c.createdAt < :before')
            ->setParameter('status', CheckoutStatus::PENDING)
            ->setParameter('before', $createdBefore)
            ->orderBy('c.updatedAt', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Попытки, требующие внимания администратора: `failed_to_apply` и `pending` с платежом ЮKassa,
     * созданные раньше `$stalePendingBefore` (сверка не может довести их до конца); без оплаты
     * с ID платежа ЮKassa в номере (её отметка вручную снимает попытку из списка).
     *
     * @return list<BillingCheckout>
     */
    public function findNeedingAttentionByCompanyId(string $companyId, \DateTimeImmutable $stalePendingBefore): array
    {
        /** @var list<BillingCheckout> $rows */
        $rows = $this->createQueryBuilder('c')
            ->addSelect('t')
            ->join('c.tariff', 't')
            ->andWhere('c.companyId = :companyId')
            ->andWhere('c.status = :failed OR (c.status = :pending AND c.providerPaymentId IS NOT NULL AND c.createdAt < :staleBefore)')
            ->andWhere('NOT EXISTS (SELECT p.id FROM '.BillingPayment::class.' p WHERE p.companyId = c.companyId AND p.externalId = c.providerPaymentId)')
            ->setParameter('companyId', $companyId)
            ->setParameter('failed', CheckoutStatus::FAILED_TO_APPLY)
            ->setParameter('pending', CheckoutStatus::PENDING)
            ->setParameter('staleBefore', $stalePendingBefore)
            ->orderBy('c.createdAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
