<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class MoneyAccountBankLinkInput
{
    /** @param array<mixed>|null $authPayload */
    public function __construct(
        public string $provider,
        public string $externalAccountId,
        public string $number,
        public string $auth,
        public bool $clear,
        public bool $authProvided,
        public ?array $authPayload,
    ) {
    }
}
