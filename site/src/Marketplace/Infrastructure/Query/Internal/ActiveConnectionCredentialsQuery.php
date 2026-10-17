<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query\Internal;

use App\Marketplace\Application\DTO\Internal\ActiveConnectionCredentials;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Shared\Security\Exception\DecryptionException;
use App\Shared\Security\Exception\InvalidEncryptedPayloadException;
use App\Shared\Security\Exception\MissingEncryptionKeyException;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Активные подключения провайдера по всем компаниям с расшифрованным ключом —
 * источник снимка `GET /internal/marketplace-connections`.
 *
 * @companyScopeExempt снимок для сервисов services/ по всем компаниям — назначение
 * endpoint'а `/internal/*` (firewall internal_api); пользовательского контекста нет
 */
final readonly class ActiveConnectionCredentialsQuery
{
    public function __construct(
        private Connection $connection,
        private ConnectionApiKeyCodec $codec,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Ключ, который не удалось расшифровать, приходит как null: один сбойный
     * кабинет не должен лишать сервис конфигурации остальных.
     *
     * @return list<ActiveConnectionCredentials>
     */
    public function fetchActive(MarketplaceType $provider): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT mc.id, mc.company_id, mc.connection_type, mc.client_id,
                   mc.api_key, mc.api_key_encrypted, mc.api_key_key_version, mc.updated_at
            FROM marketplace_connections mc
            WHERE mc.marketplace = :marketplace
              AND mc.is_active = true
            ORDER BY mc.company_id, mc.connection_type, mc.created_at, mc.id
            SQL, ['marketplace' => $provider->value]);

        $result = [];
        $failedIds = [];
        foreach ($rows as $row) {
            $type = MarketplaceConnectionType::tryFrom((string) $row['connection_type']);
            if (null === $type) {
                continue;
            }

            try {
                $secret = $this->secret($row);
            } catch (DecryptionException|MissingEncryptionKeyException|InvalidEncryptedPayloadException) {
                $secret = null;
                $failedIds[] = (string) $row['id'];
            }

            $result[] = new ActiveConnectionCredentials(
                connectionId: (string) $row['id'],
                companyId: (string) $row['company_id'],
                type: $type,
                clientId: null !== $row['client_id'] ? (string) $row['client_id'] : null,
                secret: $secret,
                updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
            );
        }

        if ([] !== $failedIds) {
            $this->logger->error('Internal connections snapshot: api key decryption failed', [
                'provider' => $provider->value,
                'failed_count' => \count($failedIds),
                'connection_ids' => $failedIds,
            ]);
        }

        return $result;
    }

    /**
     * Encrypted-пара приоритетнее legacy plaintext (expand/contract, {@see ConnectionApiKeyCodec}).
     * Пустой plaintext — ключа нет (null), это не сбой.
     *
     * @param array<string, mixed> $row
     */
    private function secret(array $row): ?string
    {
        if (null !== $row['api_key_encrypted'] && null !== $row['api_key_key_version']) {
            return $this->codec->decrypt((string) $row['api_key_encrypted'], (string) $row['api_key_key_version']);
        }

        $plaintext = (string) $row['api_key'];

        return '' !== $plaintext ? $plaintext : null;
    }
}
