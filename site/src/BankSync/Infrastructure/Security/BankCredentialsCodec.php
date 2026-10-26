<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Security;

use App\BankSync\Entity\BankConnection;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Shared\Security\Exception\DecryptionException;
use App\Shared\Security\Exception\EncryptionException;
use App\Shared\Security\Exception\InvalidEncryptedPayloadException;
use App\Shared\Security\Exception\MissingEncryptionKeyException;
use App\Shared\Security\ValueObject\EncryptedPayload;

/**
 * Учётные данные подключения ↔ шифротекст. Внутри шифротекста — JSON `{type, token}`:
 * сейчас тип один, статический токен; OAuth-токены (Stage 3) добавят свой тип без смены
 * колонок.
 */
final readonly class BankCredentialsCodec
{
    private const TYPE_STATIC_TOKEN = 'static_token';

    public function __construct(private FieldEncryptionServiceInterface $encryption)
    {
    }

    public function encryptStaticToken(#[\SensitiveParameter] string $token): EncryptedBankCredentials
    {
        if ('' === trim($token)) {
            throw new EncryptionException('Bank token must not be empty.');
        }

        $plaintext = json_encode(['type' => self::TYPE_STATIC_TOKEN, 'token' => $token], \JSON_THROW_ON_ERROR);
        $payload = $this->encryption->encrypt($plaintext);
        // Проверка до сохранения: шифротекст, который не расшифровать, потерял бы токен.
        if (!hash_equals($plaintext, $this->encryption->decrypt($payload))) {
            throw new EncryptionException('Bank credentials encryption verification failed.');
        }

        return new EncryptedBankCredentials($payload->toStorageJson(), $payload->keyVersion());
    }

    /**
     * Сообщения исключений токена не содержат.
     *
     * @throws DecryptionException шифротекст не расшифровывается или формат неожиданный
     * @throws InvalidEncryptedPayloadException шифротекст повреждён
     * @throws MissingEncryptionKeyException ключа этой версии нет
     */
    public function token(BankConnection $connection): string
    {
        $plaintext = $this->encryption->decrypt(EncryptedPayload::fromStorageJson($connection->getCredentialsEncrypted()));

        try {
            $credentials = json_decode($plaintext, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DecryptionException('Bank credentials have an unexpected format.');
        }

        if (!\is_array($credentials)
            || self::TYPE_STATIC_TOKEN !== ($credentials['type'] ?? null)
            || !\is_string($credentials['token'] ?? null)
        ) {
            throw new DecryptionException('Bank credentials have an unexpected format.');
        }

        return $credentials['token'];
    }
}
