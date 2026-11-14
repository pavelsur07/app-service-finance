<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO\PaymentIntake;

final readonly class RequestPaymentIntakeCommand
{
    public function __construct(public string $companyId, public string $actorUserId, public string $moneyAccountId, public string $sourceKey, public string $text, public \DateTimeImmutable $receivedAt, public string $sourceRevision = 'original', public string $timezone = 'Europe/Moscow')
    {
    }
}
