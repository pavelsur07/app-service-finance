<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO\PaymentIntake;

final readonly class PaymentIntakeDraftView
{
    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $questions
     */
    public function __construct(public string $id, public string $companyId, public string $actorUserId, public string $moneyAccountId, public int $revision, public string $status, public ?string $taskId, public array $items, public array $questions, public ?string $errorCode)
    {
    }
}
