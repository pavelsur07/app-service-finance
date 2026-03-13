<?php

declare(strict_types=1);

namespace App\Shared\Security\Contract;

use App\Shared\Security\ValueObject\EncryptedPayload;

interface FieldEncryptionServiceInterface
{
    public function encrypt(string $plaintext): EncryptedPayload;

    public function decrypt(EncryptedPayload $payload): string;
}
