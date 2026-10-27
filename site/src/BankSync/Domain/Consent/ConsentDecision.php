<?php

declare(strict_types=1);

namespace App\BankSync\Domain\Consent;

use App\BankSync\Enum\ConsentState;

final readonly class ConsentDecision
{
    public function __construct(
        public ConsentState $state,
        /** Последний день действующего согласия; только у `ACTIVE`. */
        public ?\DateTimeImmutable $expiresOn = null,
        /** Осталось `ConsentExpiryPolicy::WARNING_DAYS` дней и меньше. */
        public bool $expiresSoon = false,
    ) {
    }
}
