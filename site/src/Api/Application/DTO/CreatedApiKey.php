<?php

declare(strict_types=1);

namespace App\Api\Application\DTO;

use App\Api\Entity\ApiKey;
use Symfony\Component\Serializer\Attribute\Ignore;

final readonly class CreatedApiKey
{
    public function __construct(public ApiKey $key, #[Ignore] #[\SensitiveParameter] private string $token)
    {
    }

    #[Ignore]
    public function revealToken(): string
    {
        return $this->token;
    }

    /** @return array{key: ApiKey} */
    public function __debugInfo(): array
    {
        return ['key' => $this->key];
    }

    public function __serialize(): array
    {
        throw new \LogicException('A one-time API secret cannot be serialized.');
    }
}
