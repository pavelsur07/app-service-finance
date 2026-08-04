<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Security;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Shared\Security\ValueObject\EncryptedPayload;

/**
 * Кодек api_key подключений маркетплейсов (в т.ч. Ozon Performance client_secret,
 * который хранится в том же поле).
 *
 * Expand/contract:
 *  - expand: applyApiKey() пишет ОБА представления — legacy plaintext-колонку
 *    (для отката и старого кода) и encrypted-пару (ciphertext + keyVersion);
 *  - чтение всегда через apiKeyFor(): предпочитает encrypted, fallback на plaintext;
 *  - contract (отдельная задача): прекращение записи plaintext и удаление колонки.
 */
final readonly class ConnectionApiKeyCodec
{
    public function __construct(
        private FieldEncryptionServiceInterface $encryptionService,
    ) {
    }

    /**
     * Записывает api-ключ в подключение: legacy plaintext + encrypted-пара.
     */
    public function applyApiKey(MarketplaceConnection $connection, string $plaintext): void
    {
        $connection->setApiKey($plaintext);

        $payload = $this->encryptionService->encrypt($plaintext);
        $connection->setEncryptedApiKey($payload->ciphertext(), $payload->keyVersion());
    }

    /**
     * Шифрует существующий plaintext-ключ (backfill), plaintext не трогает.
     */
    public function encryptExisting(MarketplaceConnection $connection): void
    {
        $payload = $this->encryptionService->encrypt($connection->getApiKey());
        $connection->setEncryptedApiKey($payload->ciphertext(), $payload->keyVersion());
    }

    /**
     * Возвращает пригодный к использованию plaintext-ключ:
     * расшифровывает encrypted-пару, если она есть, иначе legacy plaintext.
     */
    public function apiKeyFor(MarketplaceConnection $connection): string
    {
        if ($connection->hasEncryptedApiKey()) {
            return $this->decrypt(
                $connection->getApiKeyEncrypted(),
                $connection->getApiKeyKeyVersion(),
            );
        }

        return $connection->getApiKey();
    }

    public function decrypt(string $ciphertext, string $keyVersion): string
    {
        return $this->encryptionService->decrypt(new EncryptedPayload(
            ciphertext: $ciphertext,
            keyVersion: $keyVersion,
            encryptedAt: new \DateTimeImmutable(),
        ));
    }

    public function encrypt(string $plaintext): EncryptedPayload
    {
        return $this->encryptionService->encrypt($plaintext);
    }
}
