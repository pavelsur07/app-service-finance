<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Facade;

use App\MarketplaceOperations\Application\DTO\DayOperations;
use App\MarketplaceOperations\Application\DTO\DayWindow;
use App\MarketplaceOperations\Application\DTO\OperationRecord;
use App\MarketplaceOperations\Enum\OperationKind;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;

/**
 * Единственная запись канона. Замена окна, а не дельта: удалить день кабинета, вставить
 * заново, отметить окно грязным — под `pg_advisory_xact_lock` дня, чтобы две замены одного
 * дня не смешали строки. Блокировка одна на вызов, поэтому порядок блокировок не нужен.
 */
final readonly class MarketplaceOperationsFacade implements MarketplaceOperationsFacadeInterface
{
    private const CHUNK = 500;

    private const COLUMNS = ['id', 'company_id', 'channel', 'connection_ref', 'operation_date', 'listing_id', 'source_type', 'kind', 'amount_minor', 'currency', 'quantity', 'source_refs', 'rules_version', 'allocation_key', 'created_at'];

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function replaceDay(DayOperations $day): int
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('MarketplaceOperations: replaceDay вызывается внутри транзакции вызывающего');
        }

        $date = $day->date->format('Y-m-d');
        $window = ['company' => $day->companyId, 'channel' => $day->channel, 'connection' => $day->connectionRef, 'date' => $date];
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->lockDay('mo:'.implode(':', $window));
        $this->connection->executeStatement(
            'DELETE FROM mo_operation WHERE company_id = :company AND channel = :channel AND connection_ref = :connection AND operation_date = :date',
            $window,
        );

        foreach (array_chunk($day->operations, self::CHUNK) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $operation) {
                $rows[] = '('.implode(', ', array_fill(0, \count(self::COLUMNS), '?')).')';
                array_push(
                    $params,
                    Uuid::uuid7()->toString(),
                    $day->companyId,
                    $day->channel,
                    $day->connectionRef,
                    $date,
                    $operation->listingId,
                    $operation->sourceType,
                    $operation->kind->value,
                    $operation->amount->amountMinor(),
                    $operation->amount->currency(),
                    $operation->quantity,
                    json_encode($operation->sourceRefs, \JSON_THROW_ON_ERROR),
                    $day->rulesVersion,
                    $operation->allocationKey,
                    $now,
                );
            }
            $this->connection->executeStatement(
                'INSERT INTO mo_operation ('.implode(', ', self::COLUMNS).') VALUES '.implode(', ', $rows),
                $params,
            );
        }

        $this->connection->executeStatement(
            'INSERT INTO mo_dirty_window (company_id, channel, connection_ref, operation_date, marked_at)
             VALUES (:company, :channel, :connection, :date, :now)
             ON CONFLICT (company_id, channel, connection_ref, operation_date) DO UPDATE SET marked_at = EXCLUDED.marked_at',
            $window + ['now' => $now],
        );

        return \count($day->operations);
    }

    public function dirtyDays(int $limit, ?\DateTimeImmutable $markedBefore = null): array
    {
        $params = ['limit' => max(1, $limit)];
        if (null !== $markedBefore) {
            $params['before'] = $markedBefore->format('Y-m-d H:i:s');
        }

        return $this->windows($this->connection->fetchAllAssociative(
            'SELECT company_id, channel, connection_ref, operation_date FROM mo_dirty_window'
            .(null === $markedBefore ? '' : ' WHERE marked_at < :before').' ORDER BY marked_at, operation_date LIMIT :limit',
            $params,
        ));
    }

    public function days(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->windows($this->connection->fetchAllAssociative(
            'SELECT DISTINCT company_id, channel, connection_ref, operation_date FROM mo_operation
             WHERE operation_date BETWEEN :from AND :to ORDER BY operation_date, company_id, channel, connection_ref',
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        ));
    }

    public function takeDay(DayWindow $day): array
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('MarketplaceOperations: takeDay вызывается внутри транзакции вызывающего');
        }

        $window = ['company' => $day->companyId, 'channel' => $day->channel, 'connection' => $day->connectionRef, 'date' => $day->date];
        $this->lockDay('mo:'.implode(':', $window));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT listing_id, kind, source_type, amount_minor, currency, quantity FROM mo_operation
             WHERE company_id = :company AND channel = :channel AND connection_ref = :connection AND operation_date = :date
             ORDER BY id',
            $window,
        );
        $this->connection->executeStatement(
            'DELETE FROM mo_dirty_window WHERE company_id = :company AND channel = :channel AND connection_ref = :connection AND operation_date = :date',
            $window,
        );

        return array_map(static fn (array $r): OperationRecord => new OperationRecord(
            null === $r['listing_id'] ? null : (string) $r['listing_id'],
            OperationKind::from((string) $r['kind']),
            (string) $r['source_type'],
            (int) $r['amount_minor'],
            (string) $r['currency'],
            null === $r['quantity'] ? null : (int) $r['quantity'],
        ), $rows);
    }

    private function lockDay(string $key): void
    {
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => $key]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<DayWindow>
     */
    private function windows(array $rows): array
    {
        return array_map(static fn (array $r): DayWindow => new DayWindow(
            (string) $r['company_id'],
            (string) $r['channel'],
            (string) $r['connection_ref'],
            (string) $r['operation_date'],
        ), $rows);
    }
}
