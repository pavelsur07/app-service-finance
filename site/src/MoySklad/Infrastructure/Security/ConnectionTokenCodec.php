<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Security;

use App\MoySklad\Entity\MoySkladConnection;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Shared\Security\Exception\EncryptionException;
use App\Shared\Security\ValueObject\EncryptedPayload;

final readonly class ConnectionTokenCodec
{
    public function __construct(private FieldEncryptionServiceInterface $encryption)
    {
    }

    public function accessTokenFor(MoySkladConnection $connection): ?string
    {
        $encrypted = $connection->getAccessTokenEncrypted();

        return null !== $encrypted
            ? $this->encryption->decrypt(EncryptedPayload::fromStorageJson($encrypted))
            : $connection->getAccessToken();
    }

    public function replaceAccessToken(MoySkladConnection $connection, #[\SensitiveParameter] string $token): void
    {
        $payload = $this->verifiedPayload($token, null);
        $connection->setAccessTokenEncrypted($payload);
        $connection->setAccessToken(null);
    }

    public function encryptExisting(MoySkladConnection $connection): bool
    {
        $access = $connection->getAccessToken();
        $refresh = $connection->getRefreshToken();
        if (null === $access && null === $refresh) {
            return false;
        }

        // Prepare and verify both values before changing either original.
        $encryptedAccess = null !== $access ? $this->verifiedPayload($access, $connection->getAccessTokenEncrypted()) : null;
        $encryptedRefresh = null !== $refresh ? $this->verifiedPayload($refresh, $connection->getRefreshTokenEncrypted()) : null;
        if (null !== $access) {
            $connection->setAccessTokenEncrypted($encryptedAccess);
            $connection->setAccessToken(null);
        }
        if (null !== $refresh) {
            $connection->setRefreshTokenEncrypted($encryptedRefresh);
            $connection->setRefreshToken(null);
        }

        return true;
    }

    private function verifiedPayload(#[\SensitiveParameter] string $plaintext, ?string $existing): string
    {
        $json = $existing ?? $this->encryption->encrypt($plaintext)->toStorageJson();
        $decoded = $this->encryption->decrypt(EncryptedPayload::fromStorageJson($json));
        if (!hash_equals($plaintext, $decoded)) {
            throw new EncryptionException('Connection token encryption verification failed.');
        }

        return $json;
    }
}
