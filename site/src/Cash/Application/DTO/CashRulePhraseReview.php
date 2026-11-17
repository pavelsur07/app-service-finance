<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CashRulePhraseReview
{
    /** @param array<string, list<CashRulePhraseExample>> $examples */
    public function __construct(
        public ?CashTransactionAutoRuleCandidate $candidate,
        public int $matches,
        public int $other,
        public array $examples,
    ) {
    }
}
