<?php

declare(strict_types=1);

namespace App\SourceFacts\Facade;

use App\SourceFacts\Application\DTO\SourceFactRecord;
use App\SourceFacts\Application\DTO\SourcePeriod;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

final readonly class SourceFactsFacade implements SourceFactsFacadeInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @companyScopeExempt переводчик обходит периоды всех компаний (cron)
     */
    public function periods(string $resourceType): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT company_id, connection_ref, period_key, current_revision FROM sf_period
             WHERE resource_type = :resource ORDER BY company_id, connection_ref, period_key',
            ['resource' => $resourceType],
        );

        return array_map(
            static fn (array $row): SourcePeriod => new SourcePeriod((string) $row['company_id'], (string) $row['connection_ref'], (string) $row['period_key'], (int) $row['current_revision']),
            $rows,
        );
    }

    public function activeFacts(string $companyId, string $connectionRef, string $resourceType, string $periodKey): array
    {
        Assert::uuid($companyId);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT f.source_key, f.payload::text AS payload
             FROM sf_fact f
             JOIN sf_period p ON p.company_id = f.company_id AND p.connection_ref = f.connection_ref
                  AND p.resource_type = f.resource_type AND p.period_key = f.period_key
             WHERE f.company_id = :company AND f.connection_ref = :connection AND f.resource_type = :resource AND f.period_key = :period
               AND f.first_revision <= p.current_revision AND f.last_seen_revision >= p.current_revision
             ORDER BY f.source_key',
            ['company' => $companyId, 'connection' => $connectionRef, 'resource' => $resourceType, 'period' => $periodKey],
        );

        return array_map(
            static function (array $row): SourceFactRecord {
                $payload = json_decode((string) $row['payload'], true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
                Assert::isArray($payload);

                /* @var array<string, mixed> $payload */
                return new SourceFactRecord((string) $row['source_key'], $payload);
            },
            $rows,
        );
    }
}
