<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\BillingCheckout;
use App\Billing\Entity\BillingPayment;
use App\Billing\Entity\Tariff;
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
     * Есть ли у компании незавершённая попытка другого тарифа, созданная после `$createdAfter`
     * (кроме `$exceptId`): по ней клиент может заплатить — со ссылкой сейчас, без ссылки после повтора.
     * По дате создания, не `updatedAt`: его двигает и cron-сверка, брошенная попытка блокировала бы вечно.
     */
    public function hasOpenForOtherTariff(string $companyId, Tariff $tariff, \DateTimeImmutable $createdAfter, ?string $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->select('1')
            ->andWhere('c.companyId = :companyId')
            ->andWhere('c.status = :status')
            ->andWhere('c.tariff <> :tariff')
            ->andWhere('c.createdAt > :after');
        if (null !== $exceptId) {
            $qb->andWhere('c.id <> :exceptId')->setParameter('exceptId', $exceptId);
        }

        return null !== $qb
            ->setParameter('companyId', $companyId)
            ->setParameter('status', CheckoutStatus::PENDING)
            ->setParameter('tariff', $tariff)
            ->setParameter('after', $createdAfter)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
