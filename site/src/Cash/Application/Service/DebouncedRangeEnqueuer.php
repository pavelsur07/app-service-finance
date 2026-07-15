<?php

namespace App\Cash\Application\Service;

use Symfony\Component\Lock\LockFactory;

final class DebouncedRangeEnqueuer
{
    private const TTL_SECONDS = 120;

    public function __construct(private readonly LockFactory $lockFactory)
    {
    }

    public function shouldEnqueueCompanyDay(string $companyId, \DateTimeImmutable $day, string $moneyAccountId): bool
    {
        $key = implode('.', ['auto_rules.enqueue', $companyId, $day->format('Y-m-d'), $moneyAccountId]);
        $lock = $this->lockFactory->createLock($key, self::TTL_SECONDS, false);

        return $lock->acquire();
    }
}
