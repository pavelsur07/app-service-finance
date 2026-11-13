<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO\PaymentIntake;

final readonly class AmendPaymentIntakeDraftCommand
{
    /**
     * @param array<string, array<string, mixed>> $items
     * @param list<string>|null $questions
     */
    public function __construct(public string $companyId, public string $actorUserId, public string $draftId, public int $expectedRevision, public array $items = [], public bool $retry = false, public ?string $text = null, public ?string $sourceRevision = null, public ?array $questions = null)
    {
    }
}
