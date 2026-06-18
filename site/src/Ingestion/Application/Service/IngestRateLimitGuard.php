<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Webmozart\Assert\Assert;

final readonly class IngestRateLimitGuard
{
    public function __construct(private LockFactory $lockFactory)
    {
    }

    public function acquire(string $sourceKey, int $maxLockMs = 60000): LockInterface
    {
        Assert::notEmpty($sourceKey);
        Assert::greaterThanEq($maxLockMs, 1);

        $lock = $this->lockFactory->createLock(sprintf('ingest_rate:%s', $sourceKey), $maxLockMs / 1000, false);
        if (!$lock->acquire()) {
            throw new \RuntimeException(sprintf('Ingestion rate limit lock is already held for key "%s".', $sourceKey));
        }

        return $lock;
    }
}
