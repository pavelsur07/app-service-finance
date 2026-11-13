<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceOperations;

use App\MarketplaceOperations\Application\DTO\DayOperations;
use App\MarketplaceOperations\Application\DTO\OperationInput;
use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacade;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

final class ReplaceDayTest extends IntegrationTestCase
{
    private MarketplaceOperationsFacadeInterface $facade;
    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        // Потребителя фасада ещё нет (этап 4), контейнер вырезает сервис — собираем напрямую.
        $this->facade = new MarketplaceOperationsFacade($this->connection, new MockClock('2026-11-13 10:00:00'));
        $this->companyId = Uuid::uuid7()->toString();
    }

    public function testRepeatedReplaceKeepsSameRowsAndTouchesOnlyItsDay(): void
    {
        $this->replace($this->day('2026-11-12', [$this->sale('4007.00'), $this->op(OperationKind::SPP, 'ozon:posting:bonus', '-2152.45')]));
        $this->replace($this->day('2026-11-11', [$this->sale('100.00')]));
        $this->replace($this->day('2026-11-12', [$this->sale('4007.00'), $this->op(OperationKind::SPP, 'ozon:posting:bonus', '-2152.45')], 'cabinet-2'));

        self::assertSame(2, $this->replace($this->day('2026-11-12', [$this->sale('4007.00'), $this->op(OperationKind::SPP, 'ozon:posting:bonus', '-2152.45')])));

        self::assertSame(['2026-11-11' => 1, '2026-11-12' => 4], $this->countsByDay());
        self::assertSame('185455', (string) $this->connection->fetchOne(
            'SELECT SUM(amount_minor) FROM mo_operation WHERE company_id = ? AND connection_ref = ? AND operation_date = ?',
            [$this->companyId, 'cabinet-1', '2026-11-12'],
        ));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mo_dirty_window WHERE company_id = ?', [$this->companyId]));
    }

    public function testReplaceWithFewerOperationsRemovesOldOnesAndKeepsStornoSign(): void
    {
        $this->replace($this->day('2026-11-12', [$this->sale('500.00'), $this->op(OperationKind::LOGISTICS_TO, 'ozon:service:32', '-80.00')]));

        $this->replace($this->day('2026-11-12', [$this->op(OperationKind::LOGISTICS_TO, 'ozon:service:32', '80.00')]));

        $row = $this->connection->fetchAssociative('SELECT kind, source_type, amount_minor, source_refs FROM mo_operation WHERE company_id = ?', [$this->companyId]);
        self::assertSame(['2026-11-12' => 1], $this->countsByDay());
        self::assertSame('logistics_to', $row['kind'] ?? null);
        self::assertSame('ozon:service:32', $row['source_type'] ?? null);
        self::assertSame(8000, (int) ($row['amount_minor'] ?? 0));
        self::assertSame(['ozon:accrual-by-day:1'], json_decode((string) ($row['source_refs'] ?? ''), true));
    }

    public function testReplaceOutsideTransactionIsRejected(): void
    {
        $this->expectException(\LogicException::class);

        $this->facade->replaceDay($this->day('2026-11-12', [$this->sale('1.00')]));
    }

    public function testInvalidOperationIsRejectedBeforeAnythingIsWritten(): void
    {
        foreach ([
            fn () => new OperationInput(OperationKind::SALE, ' ', Money::fromString('1.00', 'RUB'), ['ozon:accrual-by-day:1']),
            fn () => new OperationInput(OperationKind::SALE, 'ozon:sale', Money::fromString('1.00', 'RUB'), []),
            fn () => new DayOperations($this->companyId, 'Ozon', 'cabinet-1', new \DateTimeImmutable('2026-11-12'), 'ozon-v1', []),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('ожидался отказ');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\ValueError::class);
        OperationKind::from('logistics');
    }

    public function testConcurrentReplaceOfSameDayWaitsForLock(): void
    {
        $other = DriverManager::getConnection($this->connection->getParams());
        $other->beginTransaction();
        $other->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['mo:'.$this->companyId.':ozon:cabinet-1:2026-11-12']);

        try {
            $this->connection->beginTransaction();
            $this->connection->executeStatement("SET LOCAL lock_timeout = '200ms'");
            try {
                $this->facade->replaceDay($this->day('2026-11-12', [$this->sale('1.00')]));
                self::fail('замена дня должна ждать блокировку другой транзакции');
            } catch (DbalException) {
            } finally {
                $this->connection->rollBack();
            }
        } finally {
            $other->rollBack();
            $other->close();
        }

        self::assertSame(1, $this->replace($this->day('2026-11-12', [$this->sale('1.00')])));
    }

    /**
     * @param list<OperationInput> $operations
     */
    private function day(string $date, array $operations, string $connectionRef = 'cabinet-1'): DayOperations
    {
        return new DayOperations($this->companyId, 'ozon', $connectionRef, new \DateTimeImmutable($date), 'ozon-v1', $operations);
    }

    private function sale(string $amount): OperationInput
    {
        return new OperationInput(OperationKind::SALE, 'ozon:posting:seller_price', Money::fromString($amount, 'RUB'), ['ozon:accrual-by-day:1'], Uuid::uuid7()->toString(), 1);
    }

    private function op(OperationKind $kind, string $sourceType, string $amount): OperationInput
    {
        return new OperationInput($kind, $sourceType, Money::fromString($amount, 'RUB'), ['ozon:accrual-by-day:1']);
    }

    private function replace(DayOperations $day): int
    {
        return $this->connection->transactional(fn (): int => $this->facade->replaceDay($day));
    }

    /**
     * @return array<string, int>
     */
    private function countsByDay(): array
    {
        $counts = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT operation_date, COUNT(*) AS n FROM mo_operation WHERE company_id = ? GROUP BY operation_date ORDER BY operation_date',
            [$this->companyId],
        ) as $row) {
            $counts[(string) $row['operation_date']] = (int) $row['n'];
        }

        return $counts;
    }
}
