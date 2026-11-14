<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO\PaymentIntake;

final readonly class ConfirmPaymentIntakeDraftCommand
{
    /** @param list<string> $itemIds */
    public function __construct(public string $companyId, public string $actorUserId, public string $draftId, public int $expectedRevision, public array $itemIds)
    {
    }
}
