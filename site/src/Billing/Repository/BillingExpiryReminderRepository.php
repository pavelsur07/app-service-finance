<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\BillingExpiryReminder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

/**
 * @extends ServiceEntityRepository<BillingExpiryReminder>
 */
final class BillingExpiryReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingExpiryReminder::class);
    }

    /**
     * Застолбить отправку письма: true — строки не было и она записана (письмо надо отправить),
     * false — уже отправлено или отправляется. Один атомарный INSERT … ON CONFLICT DO NOTHING
     * (autocommit) — повторная доставка сообщения и повтор прогона второго письма не дают.
     */
    public function claim(string $companyId, \DateTimeImmutable $paidUntil, int $daysBefore, \DateTimeImmutable $now): bool
    {
        $inserted = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO billing_expiry_reminders (id, company_id, paid_until, days_before, created_at)
             VALUES (:id, :companyId, :paidUntil, :daysBefore, :createdAt)
             ON CONFLICT (company_id, paid_until, days_before) DO NOTHING',
            [
                'id' => Uuid::uuid7()->toString(),
                'companyId' => $companyId,
                'paidUntil' => $paidUntil->format('Y-m-d'),
                'daysBefore' => $daysBefore,
                'createdAt' => $now->format('Y-m-d H:i:s'),
            ],
        );

        return 1 === $inserted;
    }

    /**
     * Снять отметку, если письмо не ушло (сбой SMTP до приёма): повтор сообщения отправит его.
     */
    public function release(string $companyId, \DateTimeImmutable $paidUntil, int $daysBefore): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM billing_expiry_reminders WHERE company_id = :companyId AND paid_until = :paidUntil AND days_before = :daysBefore',
            ['companyId' => $companyId, 'paidUntil' => $paidUntil->format('Y-m-d'), 'daysBefore' => $daysBefore],
        );
    }

    public function isSent(string $companyId, \DateTimeImmutable $paidUntil, int $daysBefore): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM billing_expiry_reminders WHERE company_id = :companyId AND paid_until = :paidUntil AND days_before = :daysBefore',
            ['companyId' => $companyId, 'paidUntil' => $paidUntil->format('Y-m-d'), 'daysBefore' => $daysBefore],
        );
    }

    public function countByCompanyId(string $companyId): int
    {
        return $this->count(['companyId' => $companyId]);
    }
}
