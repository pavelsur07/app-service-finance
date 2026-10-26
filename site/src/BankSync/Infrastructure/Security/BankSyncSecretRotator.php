<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Security;

use App\Shared\Security\Contract\SecretRotationServiceInterface;
use App\Shared\Security\Contract\SecretRotatorInterface;
use App\Shared\Security\ValueObject\EncryptedPayload;
use App\Shared\Security\ValueObject\KeyVersionCount;
use App\Shared\Security\ValueObject\RotationBatchResult;
use Doctrine\DBAL\Connection;

/**
 * `bank_sync_connection.credentials_encrypted` (JSON шифротекста) + `credentials_key_version`.
 *
 * Запросы по всем компаниям намеренно: служебная ротация ключа.
 */
final readonly class BankSyncSecretRotator implements SecretRotatorInterface
{
    public function __construct(
        private Connection $connection,
        private SecretRotationServiceInterface $rotation,
    ) {
    }

    public function module(): string
    {
        return 'bank_sync';
    }

    public function countByKeyVersion(): array
    {
        /** @var list<array{key_version: string, cnt: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT credentials_key_version AS key_version, COUNT(id) AS cnt
             FROM bank_sync_connection
             GROUP BY 1
             ORDER BY 1',
        );

        return array_map(
            fn (array $row): KeyVersionCount => new KeyVersionCount($this->module(), 'credentials', $row['key_version'], (int) $row['cnt']),
            $rows,
        );
    }

    public function rotateBatch(string $activeKeyVersion, int $limit): RotationBatchResult
    {
        /** @var list<array{id: string, credentials_encrypted: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, credentials_encrypted
             FROM bank_sync_connection
             WHERE credentials_key_version <> :active
             ORDER BY id
             LIMIT :limit',
            ['active' => $activeKeyVersion, 'limit' => $limit],
        );

        $rotated = 0;
        foreach ($rows as $row) {
            // Версия для расшифровки — из самого шифротекста; колонка — её копия для подсчёта.
            $payload = $this->rotation->rotate(EncryptedPayload::fromStorageJson($row['credentials_encrypted']));

            $rotated += (int) $this->connection->executeStatement(
                'UPDATE bank_sync_connection
                 SET credentials_encrypted = :new, credentials_key_version = :version
                 WHERE id = :id AND credentials_encrypted = :old',
                [
                    'new' => $payload->toStorageJson(),
                    'version' => $payload->keyVersion(),
                    'id' => $row['id'],
                    'old' => $row['credentials_encrypted'],
                ],
            );
        }

        return new RotationBatchResult(\count($rows), $rotated);
    }
}
