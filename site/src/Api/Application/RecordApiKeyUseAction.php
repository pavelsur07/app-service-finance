<?php

declare(strict_types=1);

namespace App\Api\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Clock\ClockInterface;

/** Usage metadata is independent of the configuration version and management audit. */
final readonly class RecordApiKeyUseAction
{
    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId, string $keyId): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $this->connection->executeStatement(
            'UPDATE api_keys SET last_used_at = :now WHERE company_id = :company AND id = :id AND revoked_at IS NULL AND expires_at > :now AND (last_used_at IS NULL OR last_used_at < :now)',
            ['now' => $now, 'company' => $companyId, 'id' => $keyId],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
