<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Security;

use App\Shared\Security\Contract\SecretRotationServiceInterface;
use App\Shared\Security\Contract\SecretRotatorInterface;
use App\Shared\Security\ValueObject\EncryptedPayload;
use App\Shared\Security\ValueObject\KeyVersionCount;
use App\Shared\Security\ValueObject\RotationBatchResult;
use Doctrine\DBAL\Connection;

/**
 * `marketplace_connections.api_key_encrypted` + `api_key_key_version`. Строки без
 * шифротекста (legacy plaintext) только считаются: их шифрует
 * `app:marketplace:encrypt-connection-keys`.
 *
 * Запросы по всем компаниям намеренно: служебная ротация ключа.
 */
final readonly class MarketplaceSecretRotator implements SecretRotatorInterface
{
    public function __construct(
        private Connection $connection,
        private SecretRotationServiceInterface $rotation,
    ) {
    }

    public function module(): string
    {
        return 'marketplace';
    }

    public function countByKeyVersion(): array
    {
        /** @var list<array{key_version: string|null, cnt: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT CASE WHEN api_key_encrypted IS NOT NULL THEN api_key_key_version END AS key_version, COUNT(id) AS cnt
             FROM marketplace_connections
             GROUP BY 1
             ORDER BY 1 NULLS FIRST',
        );

        return array_map(
            fn (array $row): KeyVersionCount => new KeyVersionCount($this->module(), 'api_key', $row['key_version'], (int) $row['cnt']),
            $rows,
        );
    }

    public function rotateBatch(string $activeKeyVersion, int $limit): RotationBatchResult
    {
        /** @var list<array{id: string, api_key_encrypted: string, api_key_key_version: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, api_key_encrypted, api_key_key_version
             FROM marketplace_connections
             WHERE api_key_encrypted IS NOT NULL AND api_key_key_version IS NOT NULL AND api_key_key_version <> :active
             ORDER BY id
             LIMIT :limit',
            ['active' => $activeKeyVersion, 'limit' => $limit],
        );

        $rotated = 0;
        foreach ($rows as $row) {
            $payload = $this->rotation->rotate(new EncryptedPayload(
                ciphertext: $row['api_key_encrypted'],
                keyVersion: $row['api_key_key_version'],
                encryptedAt: new \DateTimeImmutable(),
            ));

            $rotated += (int) $this->connection->executeStatement(
                'UPDATE marketplace_connections
                 SET api_key_encrypted = :new_ciphertext, api_key_key_version = :new_version
                 WHERE id = :id AND api_key_encrypted = :old_ciphertext AND api_key_key_version = :old_version',
                [
                    'new_ciphertext' => $payload->ciphertext(),
                    'new_version' => $payload->keyVersion(),
                    'id' => $row['id'],
                    'old_ciphertext' => $row['api_key_encrypted'],
                    'old_version' => $row['api_key_key_version'],
                ],
            );
        }

        return new RotationBatchResult(\count($rows), $rotated);
    }
}
