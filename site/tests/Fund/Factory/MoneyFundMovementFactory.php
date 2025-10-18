<?php

declare(strict_types=1);

namespace App\Tests\Fund\Factory;

use App\Entity\Company;
use App\Entity\MoneyFund;
use App\Entity\MoneyFundMovement;
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
