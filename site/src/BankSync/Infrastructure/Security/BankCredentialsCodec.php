<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Security;

use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Entity\BankConnection;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Shared\Security\Exception\DecryptionException;
use App\Shared\Security\Exception\EncryptionException;
use App\Shared\Security\Exception\InvalidEncryptedPayloadException;
use App\Shared\Security\Exception\MissingEncryptionKeyException;
use App\Shared\Security\ValueObject\EncryptedPayload;

/**
 * Учётные данные подключения ↔ шифротекст. Внутри шифротекста — JSON с полем `type`:
 * - `static_token`: `{type, token}` — токен, выданный клиенту банком;
 * - `oauth2`: `{type, access_token, refresh_token|null, access_expires_at, consent_expires_at|null}`.
 */
final readonly class BankCredentialsCodec
{
    private const TYPE_STATIC_TOKEN = 'static_token';
    private const TYPE_OAUTH2 = 'oauth2';

    public function __construct(private FieldEncryptionServiceInterface $encryption)
    {
    }

    public function encryptStaticToken(#[\SensitiveParameter] string $token): EncryptedBankCredentials
    {
        if ('' === trim($token)) {
            throw new EncryptionException('Bank token must not be empty.');
        }

        return $this->encryptJson(json_encode(['type' => self::TYPE_STATIC_TOKEN, 'token' => $token], \JSON_THROW_ON_ERROR));
    }

    public function encryptOAuthTokens(OAuthTokens $tokens): EncryptedBankCredentials
    {
        return $this->encryptJson(json_encode([
            'type' => self::TYPE_OAUTH2,
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'access_expires_at' => $tokens->accessExpiresAt->format(\DATE_ATOM),
            'consent_expires_at' => $tokens->consentExpiresAt?->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR));
    }

    private function encryptJson(#[\SensitiveParameter] string $plaintext): EncryptedBankCredentials
    {
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
        $credentials = $this->decode($connection);

        return self::TYPE_OAUTH2 === $credentials['type']
            ? $this->oauthFromArray($credentials)->accessToken
            : $this->stringField($credentials, 'token');
    }

    /**
     * @return OAuthTokens|null null — статический токен, обновлять нечего
     *
     * @throws DecryptionException|InvalidEncryptedPayloadException|MissingEncryptionKeyException
     */
    public function oauthTokens(BankConnection $connection): ?OAuthTokens
    {
        $credentials = $this->decode($connection);

        return self::TYPE_OAUTH2 === $credentials['type'] ? $this->oauthFromArray($credentials) : null;
    }

    /**
     * @return array<string, mixed>&array{type: string}
     */
    private function decode(BankConnection $connection): array
    {
        $plaintext = $this->encryption->decrypt(EncryptedPayload::fromStorageJson($connection->getCredentialsEncrypted()));

        try {
            $credentials = json_decode($plaintext, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DecryptionException('Bank credentials have an unexpected format.');
        }

        if (!\is_array($credentials) || !\in_array($credentials['type'] ?? null, [self::TYPE_STATIC_TOKEN, self::TYPE_OAUTH2], true)) {
            throw new DecryptionException('Bank credentials have an unexpected format.');
        }

        /* @var array<string, mixed>&array{type: string} $credentials */
        return $credentials;
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function oauthFromArray(array $credentials): OAuthTokens
    {
        $refresh = $credentials['refresh_token'] ?? null;
        $consent = $credentials['consent_expires_at'] ?? null;
        if ((null !== $refresh && !\is_string($refresh)) || (null !== $consent && !\is_string($consent))) {
            throw new DecryptionException('Bank credentials have an unexpected format.');
        }

        try {
            return new OAuthTokens(
                $this->stringField($credentials, 'access_token'),
                $refresh,
                new \DateTimeImmutable($this->stringField($credentials, 'access_expires_at')),
                null === $consent ? null : new \DateTimeImmutable($consent),
            );
        } catch (\Exception) {
            throw new DecryptionException('Bank credentials have an unexpected format.');
        }
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function stringField(array $credentials, string $field): string
    {
        $value = $credentials[$field] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new DecryptionException('Bank credentials have an unexpected format.');
        }

        return $value;
    }
}
