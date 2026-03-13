<?php

declare(strict_types=1);

namespace App\Shared\Security\Service;

use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Shared\Security\Contract\SecretKeyProviderInterface;
use App\Shared\Security\Contract\SecretRotationServiceInterface;
use App\Shared\Security\ValueObject\EncryptedPayload;

final readonly class SecretRotationService implements SecretRotationServiceInterface
{
    public function __construct(
        private SecretKeyProviderInterface $secretKeyProvider,
        private FieldEncryptionServiceInterface $fieldEncryptionService,
    ) {
    }

    public function requiresReencryption(EncryptedPayload $payload): bool
    {
        return $this->needsRotation($payload);
    }

    public function needsRotation(EncryptedPayload $payload): bool
    {
        return $payload->keyVersion() !== $this->secretKeyProvider->getActiveKeyVersion();
    }

    public function rotate(EncryptedPayload $payload): EncryptedPayload
    {
        if (!$this->needsRotation($payload)) {
            return $payload;
        }

        $plaintext = $this->fieldEncryptionService->decrypt($payload);

        return $this->fieldEncryptionService->encrypt($plaintext);
    }
}
