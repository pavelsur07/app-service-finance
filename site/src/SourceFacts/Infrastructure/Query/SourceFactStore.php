<?php

declare(strict_types=1);

namespace App\SourceFacts\Infrastructure\Query;

use App\ServiceGateway\Application\DTO\SourceFactBatch;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;

/**
 * Запись и чтение таблиц `sf_*` через DBAL. Транзакцией владеет вызывающий
 * (`PullSourceFactsAction`): здесь нет ни commit, ни блокировок.
 */
final readonly class SourceFactStore
{
    public const STATUS_APPLIED = 'applied';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_QUARANTINED = 'quarantined';

    private const PERIOD = 'company_id = :company AND connection_ref = :connection AND resource_type = :resource AND period_key = :period';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function currentRevision(SourceFactBatch $batch): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT current_revision FROM sf_period WHERE '.self::PERIOD,
            $this->periodParams($batch),
        );
    }

    /**
     * Записи страницы ревизии. Повтор безопасен. Запись, которой не было в текущей ревизии
     * (исчезала и вернулась с тем же содержимым), начинает действовать заново с этой ревизии.
     * Записи режет PostgreSQL из тела ответа: числа источника не проходят через float PHP.
     * Поэтому id строки — `gen_random_uuid()` (v4), а не UUIDv7 из PHP: порядок по id не используется.
     */
    public function stagePage(SourceFactBatch $batch, string $body, int $currentRevision): void
    {
        $this->connection->executeStatement(
            'INSERT INTO sf_fact (id, company_id, connection_ref, resource_type, period_key, source_key, payload_hash, payload, first_revision, last_seen_revision, created_at)
             SELECT DISTINCT ON (r->>\'source_key\', r->>\'payload_hash\')
                    gen_random_uuid(), :company, :connection, :resource, :period, r->>\'source_key\', r->>\'payload_hash\', r->\'payload\', :revision, :revision, :now
             FROM jsonb_array_elements(CAST(:body AS jsonb)->\'records\') AS r
             ON CONFLICT (company_id, connection_ref, resource_type, period_key, source_key, payload_hash) DO UPDATE SET
                    first_revision = CASE WHEN sf_fact.last_seen_revision < :current THEN EXCLUDED.first_revision ELSE sf_fact.first_revision END,
                    last_seen_revision = GREATEST(sf_fact.last_seen_revision, EXCLUDED.last_seen_revision)',
            $this->periodParams($batch) + ['revision' => $batch->revision, 'current' => $currentRevision, 'body' => $body, 'now' => $this->now()],
        );
    }

    /**
     * Ревизия пачки становится текущей, если она новее: монотонность держит сам оператор
     * (`WHERE current_revision < …`), поэтому гонка двух активаций не откатывает период назад.
     *
     * @return int|null число действующих записей; null — текущая ревизия не старше, ничего не изменено
     */
    public function activate(SourceFactBatch $batch): ?int
    {
        $count = $this->connection->fetchOne(
            'INSERT INTO sf_period (id, company_id, connection_ref, resource_type, period_key, current_revision, fact_count, updated_at)
             SELECT :id, :company, :connection, :resource, :period, :revision, count(*), :now
             FROM sf_fact WHERE '.self::PERIOD.' AND first_revision <= :revision AND last_seen_revision >= :revision
             ON CONFLICT (company_id, connection_ref, resource_type, period_key) DO UPDATE SET
                    current_revision = EXCLUDED.current_revision, fact_count = EXCLUDED.fact_count, updated_at = EXCLUDED.updated_at
             WHERE sf_period.current_revision < EXCLUDED.current_revision
             RETURNING fact_count',
            $this->periodParams($batch) + ['revision' => $batch->revision, 'id' => Uuid::uuid7()->toString(), 'now' => $this->now()],
        );

        return false === $count ? null : (int) $count;
    }

    /**
     * Строка журнала пачки; повтор обновляет статус и счётчик попыток. Применённая пачка, пришедшая
     * снова (ack не дошёл), остаётся `applied`.
     *
     * @return bool true — пачка в этом статусе записана впервые
     */
    public function journal(SourceFactBatch $batch, string $status, ?string $reason = null, ?string $details = null): bool
    {
        $attempts = (int) $this->connection->fetchOne(
            'INSERT INTO sf_batch (id, service, batch_id, company_id, connection_ref, resource_type, period_key, revision, content_hash, status, reason, details, attempts, created_at, updated_at)
             VALUES (:id, :service, :batch, :company, :connection, :resource, :period, :revision, :hash, :status, :reason, :details, 1, :now, :now)
             ON CONFLICT (service, batch_id) DO UPDATE SET
                    attempts = CASE WHEN sf_batch.status = EXCLUDED.status THEN sf_batch.attempts + 1 ELSE 1 END,
                    status = EXCLUDED.status, reason = EXCLUDED.reason, details = EXCLUDED.details,
                    company_id = EXCLUDED.company_id, updated_at = EXCLUDED.updated_at
             WHERE sf_batch.status <> :applied
             RETURNING attempts',
            $this->periodParams($batch) + [
                'service' => $batch->service,
                'batch' => $batch->batchId,
                'revision' => $batch->revision,
                'hash' => $batch->contentHash,
                'id' => Uuid::uuid7()->toString(),
                'now' => $this->now(),
                'applied' => self::STATUS_APPLIED,
                'status' => $status,
                'reason' => $reason,
                'details' => null === $details ? null : mb_substr($details, 0, 500),
            ],
        );

        return 1 === $attempts;
    }

    /**
     * Для гейта: пачки в карантине и потоки (кабинет × ресурс), в которые давно ничего не приходило.
     *
     * @companyScopeExempt системный гейт по всем компаниям
     *
     * @return array{quarantined: int, quarantineReasons: list<string>, staleStreams: list<array{company_id: string, connection_ref: string, resource_type: string}>}
     */
    public function health(\DateTimeImmutable $staleBefore): array
    {
        /** @var list<string> $reasons */
        $reasons = $this->connection->fetchFirstColumn(
            'SELECT reason FROM sf_batch WHERE status = :status ORDER BY reason',
            ['status' => self::STATUS_QUARANTINED],
        );
        /** @var list<array{company_id: string, connection_ref: string, resource_type: string}> $stale */
        $stale = $this->connection->fetchAllAssociative(
            'SELECT company_id, connection_ref, resource_type FROM sf_period
             GROUP BY company_id, connection_ref, resource_type
             HAVING max(updated_at) < :before
             ORDER BY company_id, connection_ref, resource_type',
            ['before' => $staleBefore->format('Y-m-d H:i:s')],
        );

        return ['quarantined' => \count($reasons), 'quarantineReasons' => array_values(array_unique($reasons)), 'staleStreams' => $stale];
    }

    private function now(): string
    {
        // Время в БД — как у остальных timestamp приложения (часовой пояс PHP), без зоны.
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * @return array{company: string|null, connection: string, resource: string, period: string}
     */
    private function periodParams(SourceFactBatch $batch): array
    {
        return [
            'company' => $batch->companyId,
            'connection' => $batch->connectionRef,
            'resource' => $batch->resourceType,
            'period' => $batch->periodKey,
        ];
    }
}
