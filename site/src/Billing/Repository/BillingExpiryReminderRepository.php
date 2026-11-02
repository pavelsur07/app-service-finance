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
     * Застолбить напоминание: true — строки не было и она записана (письмо надо отправить),
     * false — уже отправляли. Один атомарный INSERT … ON CONFLICT DO NOTHING (autocommit,
     * если вызывающий не держит транзакцию) — повтор прогона второго письма не даёт.
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

    public function countByCompanyId(string $companyId): int
    {
        return $this->count(['companyId' => $companyId]);
    }
}
