<?php

declare(strict_types=1);

namespace App\Api\Security;

/** Server-owned operation provenance. No client input is trusted as an actor/channel. */
final readonly class ApiRequestContext
{
    public string $channel;
    public ?string $userId;

    public function __construct(
        public string $companyId,
        public string $keyId,
        public string $requestId,
    ) {
        $this->channel = 'api';
        $this->userId = null;
    }
}
