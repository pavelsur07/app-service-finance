<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Security;

use App\Shared\Security\Contract\SecretRotationServiceInterface;
use App\Shared\Security\Contract\SecretRotatorInterface;
use App\Shared\Security\ValueObject\EncryptedPayload;
use App\Shared\Security\ValueObject\KeyVersionCount;
use App\Shared\Security\ValueObject\RotationBatchResult;
use Doctrine\DBAL\Connection;

/**
 * `moysklad_connections.access_token_encrypted` и `refresh_token_encrypted`: версия
 * ключа — внутри JSON шифротекста (`EncryptedPayload::toStorageJson()`).
 *
 * Запросы по всем компаниям намеренно: служебная ротация ключа.
 */
final readonly class MoySkladSecretRotator implements SecretRotatorInterface
{
    private const FIELDS = ['access_token', 'refresh_token'];

    public function __construct(
        private Connection $connection,
        private SecretRotationServiceInterface $rotation,
    ) {
    }

    public function module(): string
    {
        return 'moysklad';
    }

    public function countByKeyVersion(): array
    {
        $counts = [];
        foreach (self::FIELDS as $field) {
            /** @var list<array{key_version: string|null, cnt: int|string}> $rows */
            $rows = $this->connection->fetchAllAssociative(sprintf(
                'SELECT (%1$s_encrypted::json ->> \'keyVersion\') AS key_version, COUNT(id) AS cnt
                 FROM moysklad_connections
                 WHERE %1$s_encrypted IS NOT NULL OR %1$s IS NOT NULL
                 GROUP BY 1
                 ORDER BY 1 NULLS FIRST',
                $field,
            ));

            foreach ($rows as $row) {
                $counts[] = new KeyVersionCount($this->module(), $field, $row['key_version'], (int) $row['cnt']);
            }
        }

        return $counts;
    }

    public function rotateBatch(string $activeKeyVersion, int $limit): RotationBatchResult
    {
        /** @var list<array{id: string, access_token_encrypted: string|null, refresh_token_encrypted: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, access_token_encrypted, refresh_token_encrypted
             FROM moysklad_connections
             WHERE (access_token_encrypted IS NOT NULL AND access_token_encrypted::json ->> \'keyVersion\' <> :active)
                OR (refresh_token_encrypted IS NOT NULL AND refresh_token_encrypted::json ->> \'keyVersion\' <> :active)
             ORDER BY id
             LIMIT :limit',
            ['active' => $activeKeyVersion, 'limit' => $limit],
        );

        $selected = 0;
        $rotated = 0;
        foreach ($rows as $row) {
            foreach (self::FIELDS as $field) {
                $stored = $row[$field.'_encrypted'];
                if (null === $stored) {
                    continue;
                }

                $payload = EncryptedPayload::fromStorageJson($stored);
                if (!$this->rotation->requiresReencryption($payload)) {
                    continue;
                }

                ++$selected;
                $rotated += (int) $this->connection->executeStatement(
                    sprintf('UPDATE moysklad_connections SET %1$s_encrypted = :new WHERE id = :id AND %1$s_encrypted = :old', $field),
                    ['new' => $this->rotation->rotate($payload)->toStorageJson(), 'id' => $row['id'], 'old' => $stored],
                );
            }
        }

        return new RotationBatchResult($selected, $rotated);
    }
}
