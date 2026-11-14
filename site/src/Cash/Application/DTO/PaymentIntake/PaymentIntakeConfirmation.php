<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO\PaymentIntake;

final readonly class PaymentIntakeConfirmation
{
    /** @param list<string> $transactionIds */
    public function __construct(public string $draftId, public array $transactionIds)
    {
    }
}
