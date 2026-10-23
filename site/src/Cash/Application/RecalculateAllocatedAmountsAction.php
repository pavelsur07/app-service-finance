<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Repository\Transaction\CashTransactionRepository;
use Doctrine\DBAL\Connection;

/**
 * Пересчитывает распределённую сумму транзакций ДДС, у которых `allocated_amount` разошёлся с
 * суммой операций живых документов ОПиУ (след удалений документов до этапа 1
 * cash-pl-idempotency).
 *
 * Транзакцией владеет вызывающая консольная команда: в режиме execute транзакция берётся под
 * FOR UPDATE до пересчёта.
 */
final class RecalculateAllocatedAmountsAction
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CashTransactionRepository $transactionRepository,
    ) {
    }

    /**
     * @return array{candidates: int, updated: int, stored_sum: string}
     */
    public function __invoke(string $companyId, bool $execute, ?int $expectedCount = null): array
    {
        if ($execute && !$this->connection->isTransactionActive()) {
            throw new \LogicException('Пересчёт выполняется внутри транзакции вызывающей команды.');
        }

        /** @var list<array{id: string, allocated_amount: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT t.id::text AS id, t.allocated_amount::text AS allocated_amount
                FROM cash_transaction t
                LEFT JOIN (
                    SELECT d.cash_transaction_id, SUM(o.amount) AS total
                    FROM documents d
                    JOIN document_operations o ON o.document_id = d.id
                    WHERE d.company_id = :company
                      AND d.cash_transaction_id IS NOT NULL
                      AND d.deleted_at IS NULL
                    GROUP BY d.cash_transaction_id
                ) live ON live.cash_transaction_id = t.id
                WHERE t.company_id = :company
                  AND t.deleted_at IS NULL
                  AND t.allocated_amount <> COALESCE(live.total, 0)
                ORDER BY t.id
                SQL,
            ['company' => $companyId],
        );

        $storedSum = '0.00';
        foreach ($rows as $row) {
            $storedSum = bcadd($storedSum, $row['allocated_amount'], 2);
        }

        if (!$execute) {
            return ['candidates' => \count($rows), 'updated' => 0, 'stored_sum' => $storedSum];
        }
        if ($expectedCount !== \count($rows)) {
            throw new \DomainException(\sprintf('Число транзакций изменилось: ожидалось %d, найдено %d. Повторите dry-run.', $expectedCount, \count($rows)));
        }

        foreach ($rows as $row) {
            // Порядок блокировок модуля: строка транзакции ДДС раньше её документов.
            $transaction = $this->transactionRepository->findOneByIdAndCompanyIdForUpdate($row['id'], $companyId);
            if (null === $transaction) {
                throw new \DomainException(\sprintf('Транзакция %s не найдена в компании.', $row['id']));
            }
            $transaction->recalculateAllocatedAmount();
        }

        return ['candidates' => \count($rows), 'updated' => \count($rows), 'stored_sum' => $storedSum];
    }
}
