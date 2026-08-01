<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\Service\CashTransactionAutoRuleProvenanceResolver;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Построчная сверка строк разбивки с колонкой cash_transaction.cashflow_category_id.
 *
 * Проверяет каждую транзакцию, а не агрегат по категории: агрегат прячет взаимно
 * компенсирующие ошибки — недостача в одной транзакции и избыток в другой дают ноль.
 *
 * Команда рассчитана на окно dual-write, где строки обязаны повторять колонку один
 * в один. После появления мультиразбивки (Stage 4) проверку expand_phase_mismatch
 * нужно версионировать: там несколько строк на транзакцию станут нормой.
 */
#[AsCommand(
    name: 'app:cash:verify-transaction-splits',
    description: 'Сверяет cash_transaction_split с колонкой категории транзакции',
)]
final class VerifyCashTransactionSplitsCommand extends Command
{
    /** @var array<string, array{title: string, sql: string}> */
    private const CHECKS = [
        'missing_splits' => [
            'title' => 'Категория задана, строк нет',
            'sql' => 'SELECT count(*) FROM cash_transaction t
                      WHERE t.cashflow_category_id IS NOT NULL
                        AND NOT EXISTS (SELECT 1 FROM cash_transaction_split s WHERE s.cash_transaction_id = t.id)',
        ],
        'unexpected_splits' => [
            'title' => 'Категории нет, строки есть',
            'sql' => 'SELECT count(*) FROM cash_transaction t
                      WHERE t.cashflow_category_id IS NULL
                        AND EXISTS (SELECT 1 FROM cash_transaction_split s WHERE s.cash_transaction_id = t.id)',
        ],
        'amount_mismatch' => [
            'title' => 'Сумма строк не равна сумме транзакции',
            'sql' => 'SELECT count(*) FROM (
                          SELECT s.cash_transaction_id, sum(s.amount) AS total
                          FROM cash_transaction_split s GROUP BY 1
                      ) g
                      JOIN cash_transaction t ON t.id = g.cash_transaction_id
                      WHERE g.total <> t.amount',
        ],
        'cross_company' => [
            'title' => 'company_id строки не совпадает с транзакцией',
            'sql' => 'SELECT count(*) FROM cash_transaction_split s
                      JOIN cash_transaction t ON t.id = s.cash_transaction_id
                      WHERE s.company_id <> t.company_id',
        ],
        'category_cross_company' => [
            'title' => 'Категория строки из другой компании',
            'sql' => 'SELECT count(*) FROM cash_transaction_split s
                      JOIN "cashflow_categories" c ON c.id = s.cashflow_category_id
                      WHERE c.company_id <> s.company_id',
        ],
        'orphan_splits' => [
            'title' => 'Строки без транзакции',
            'sql' => 'SELECT count(*) FROM cash_transaction_split s
                      WHERE NOT EXISTS (SELECT 1 FROM cash_transaction t WHERE t.id = s.cash_transaction_id)',
        ],
        'expand_phase_mismatch' => [
            'title' => 'Строки не повторяют колонку один в один (ровно одна строка той же категории)',
            'sql' => 'SELECT count(*) FROM (
                          SELECT s.cash_transaction_id, count(*) AS rows_count, min(s.cashflow_category_id::text) AS category
                          FROM cash_transaction_split s GROUP BY 1
                      ) g
                      JOIN cash_transaction t ON t.id = g.cash_transaction_id
                      WHERE t.cashflow_category_id IS NOT NULL
                        AND (g.rows_count <> 1 OR g.category <> t.cashflow_category_id::text)',
        ],
        'nonpositive_amount' => [
            'title' => 'Сумма строки не положительна',
            'sql' => 'SELECT count(*) FROM cash_transaction_split WHERE amount <= 0',
        ],
        'unknown_source' => [
            'title' => 'Недопустимое значение source',
            'sql' => "SELECT count(*) FROM cash_transaction_split WHERE source NOT IN ('manual', 'auto', 'import')",
        ],
    ];

    private const SOURCE_BATCH = 500;
    private const MISMATCH_SAMPLE = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionAutoRuleProvenanceResolver $provenanceResolver,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->printCoverage($io);

        $rows = [];
        $failed = false;
        $counts = [];

        foreach (self::CHECKS as $key => $check) {
            $count = (int) $this->connection->fetchOne($check['sql']);
            $counts[$key] = $count;
            $failed = $failed || $count > 0;
            $rows[] = [$key, $check['title'], $count, 0 === $count ? 'OK' : 'FAIL'];
        }

        $io->table(['Проверка', 'Что означает', 'Нарушений', 'Статус'], $rows);

        $sourceMismatch = $this->countSourceMismatches($io);
        if ($sourceMismatch > 0) {
            $failed = true;
            $io->warning(sprintf('Строк с source, не совпадающим с провенансом категории: %d.', $sourceMismatch));
        } else {
            $io->writeln('source строк совпадает с провенансом категории.');
        }

        // До завершения backfill сверка итогов не несёт информации: она гарантированно
        // покажет расхождение по каждой категории, у которой ещё нет строк. Печатать
        // сотни строк с идентификаторами и оборотами при заведомо известном результате —
        // только шум и лишний вынос production-данных в логи.
        if ($counts['missing_splits'] > 0) {
            $io->writeln(sprintf(
                'Сверка итогов пропущена: перенос не завершён, транзакций без строк — %d.',
                $counts['missing_splits'],
            ));
        } else {
            $totalsMismatch = $this->fetchTotalsMismatch();
            if ([] !== $totalsMismatch) {
                $failed = true;
                $this->printTotalsMismatch($io, $totalsMismatch);
            } else {
                $io->writeln('Итоги по company + category + direction + currency сходятся.');
            }
        }

        if ($failed) {
            $io->error('Сверка не пройдена.');

            return Command::FAILURE;
        }

        $io->success('Сверка пройдена.');

        return Command::SUCCESS;
    }

    /**
     * Печатает расхождение итогов без денег и без полных идентификаторов.
     *
     * Вывод команды попадает в логи и в отчёты, поэтому обороты и полные ID компаний
     * и категорий в него не идут: для диагностики хватает усечённого идентификатора,
     * чтобы найти строку руками, а полные данные достаются отдельным SQL под контролем.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> $mismatch
     */
    private function printTotalsMismatch(SymfonyStyle $io, array $mismatch): void
    {
        $io->section('Расхождение итогов по company + category + direction + currency');
        $io->writeln(sprintf('Групп с расхождением: %d.', count($mismatch)));

        $io->table(
            ['company', 'category', 'direction', 'currency'],
            array_map(
                static fn (array $row): array => [
                    substr($row[0], 0, 8),
                    substr($row[1], 0, 8),
                    $row[2],
                    $row[3],
                ],
                array_slice($mismatch, 0, self::MISMATCH_SAMPLE),
            ),
        );

        if (count($mismatch) > self::MISMATCH_SAMPLE) {
            $io->writeln(sprintf('Показаны первые %d из %d.', self::MISMATCH_SAMPLE, count($mismatch)));
        }

        $io->writeln('Суммы намеренно не выводятся. Детали — отдельным read-only SQL по идентификаторам выше.');
    }

    /**
     * Сверяет сохранённый source с провенансом, вычисленным заново.
     *
     * Это не тавтология относительно backfill: сохранённое значение писал другой код
     * в другой момент времени, поэтому расхождение ловит ошибку writer'а и дрейф данных.
     * Проверяются только строки expand-фазы — по одной на транзакцию.
     */
    private function countSourceMismatches(SymfonyStyle $io): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.cash_transaction_id AS transaction_id, s.source
             FROM cash_transaction_split s
             JOIN (SELECT cash_transaction_id FROM cash_transaction_split GROUP BY 1 HAVING count(*) = 1) one
               ON one.cash_transaction_id = s.cash_transaction_id',
        );

        $checked = 0;
        $mismatched = 0;

        foreach (array_chunk($rows, self::SOURCE_BATCH) as $chunk) {
            // Транзакции батча — одним запросом вместе с категорией: провенанс всё равно
            // читает аудит поштучно, но удваивать это ещё и на find() незачем.
            $transactions = [];
            foreach ($this->entityManager->createQueryBuilder()
                ->select('t', 'c')
                ->from(CashTransaction::class, 't')
                ->leftJoin('t.cashflowCategory', 'c')
                ->where('t.id IN (:ids)')
                ->setParameter('ids', array_column($chunk, 'transaction_id'))
                ->getQuery()
                ->getResult() as $transaction) {
                $transactions[(string) $transaction->getId()] = $transaction;
            }

            foreach ($chunk as $row) {
                $transaction = $transactions[$row['transaction_id']] ?? null;
                if (!$transaction instanceof CashTransaction) {
                    continue;
                }

                $expected = $this->provenanceResolver->resolve($transaction)->isAutoAssigned('cashflowCategory')
                    ? CashTransactionSplitSource::AUTO
                    : CashTransactionSplitSource::MANUAL;

                ++$checked;
                if ($expected->value !== $row['source']) {
                    ++$mismatched;
                }
            }

            $this->entityManager->clear();
        }

        $io->writeln(sprintf('Провенанс source проверен у %d строк из %d.', $checked, count($rows)));

        return $mismatched;
    }

    private function printCoverage(SymfonyStyle $io): void
    {
        $transactions = (int) $this->connection->fetchOne('SELECT count(*) FROM cash_transaction');
        $withCategory = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM cash_transaction WHERE cashflow_category_id IS NOT NULL',
        );
        $withSplits = (int) $this->connection->fetchOne(
            'SELECT count(DISTINCT cash_transaction_id) FROM cash_transaction_split',
        );
        $deleted = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM cash_transaction WHERE deleted_at IS NOT NULL',
        );

        $io->writeln(sprintf(
            'Покрытие: транзакций %d (из них удалённых %d), с категорией %d, со строками %d.',
            $transactions,
            $deleted,
            $withCategory,
            $withSplits,
        ));
        $io->newLine();
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function fetchTotalsMismatch(): array
    {
        $sql = <<<'SQL'
            WITH by_column AS (
                SELECT company_id, cashflow_category_id AS category_id, direction, currency, sum(amount) AS total
                FROM cash_transaction
                WHERE cashflow_category_id IS NOT NULL
                GROUP BY 1, 2, 3, 4
            ),
            by_splits AS (
                SELECT s.company_id, s.cashflow_category_id AS category_id, t.direction, t.currency, sum(s.amount) AS total
                FROM cash_transaction_split s
                JOIN cash_transaction t ON t.id = s.cash_transaction_id
                GROUP BY 1, 2, 3, 4
            )
            SELECT
                COALESCE(c.company_id::text, s.company_id::text)     AS company_id,
                COALESCE(c.category_id::text, s.category_id::text)   AS category_id,
                COALESCE(c.direction, s.direction)                   AS direction,
                COALESCE(c.currency, s.currency)                     AS currency,
                COALESCE(c.total, 0)::text                           AS column_total,
                COALESCE(s.total, 0)::text                           AS splits_total
            FROM by_column c
            FULL JOIN by_splits s
                ON  c.company_id = s.company_id
                AND c.category_id = s.category_id
                AND c.direction = s.direction
                AND c.currency = s.currency
            WHERE COALESCE(c.total, 0) <> COALESCE(s.total, 0)
            ORDER BY 1, 2, 3, 4
        SQL;

        return array_map(array_values(...), $this->connection->fetchAllAssociative($sql));
    }
}
