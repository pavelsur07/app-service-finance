<?php

declare(strict_types=1);

namespace App\Tests\Fund\Factory;

use App\Cash\Entity\Accounts\MoneyFund;
use App\Cash\Entity\Accounts\MoneyFundMovement;
use App\Entity\Company;
use Ramsey\Uuid\Uuid;

final class MoneyFundMovementFactory
{
    public static function create(
        Company $company,
        MoneyFund $fund,
        int $amountMinor,
        ?\DateTimeImmutable $occurredAt = null,
    ): MoneyFundMovement {
        return new MoneyFundMovement(
            Uuid::uuid4()->toString(),
            $company,
            $fund,
            $occurredAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $amountMinor,
        );
    }
}
