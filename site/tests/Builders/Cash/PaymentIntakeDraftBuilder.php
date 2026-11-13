<?php

declare(strict_types=1);

namespace App\Tests\Builders\Cash;

use App\Cash\Entity\PaymentIntake\PaymentIntakeDraft;
use Ramsey\Uuid\Uuid;

final class PaymentIntakeDraftBuilder
{
    private function __construct(private string $companyId, private string $actorUserId, private string $moneyAccountId)
    {
    }

    public static function aDraft(?string $companyId = null, ?string $actorUserId = null, ?string $moneyAccountId = null): self
    {
        return new self($companyId ?? Uuid::uuid7()->toString(), $actorUserId ?? Uuid::uuid7()->toString(), $moneyAccountId ?? Uuid::uuid7()->toString());
    }

    public function build(): PaymentIntakeDraft
    {
        return new PaymentIntakeDraft(
            $this->companyId,
            $this->actorUserId,
            $this->moneyAccountId,
            'bot/chat/msg',
            'original',
            'оплатил 100',
            new \DateTimeImmutable('2026-11-13T12:00:00+03:00'),
            'Europe/Moscow',
            'RUB',
        );
    }
}
