<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Security;

final readonly class EncryptedBankCredentials
{
    public function __construct(
        public string $storageJson,
        public string $keyVersion,
    ) {
    }
}
