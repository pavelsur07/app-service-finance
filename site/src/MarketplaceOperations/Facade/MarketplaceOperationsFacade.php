<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Facade;

use App\MarketplaceOperations\Application\DTO\DayOperations;
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

        $this->connection->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
            ['key' => 'mo:'.implode(':', $window)],
        );
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
}
