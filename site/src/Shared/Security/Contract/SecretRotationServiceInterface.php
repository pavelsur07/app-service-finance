<?php

declare(strict_types=1);

namespace App\Shared\Security\Contract;

use App\Shared\Security\ValueObject\EncryptedPayload;

interface SecretRotationServiceInterface
{
    public function requiresReencryption(EncryptedPayload $payload): bool;

    public function rotate(EncryptedPayload $payload): EncryptedPayload;
}
