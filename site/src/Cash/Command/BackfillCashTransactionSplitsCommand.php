<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\Service\CashTransactionAutoRuleProvenanceResolver;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Переносит категорию транзакции ДДС в строку разбивки.
 *
 * Идемпотентна: берёт только транзакции, у которых строк ещё нет, поэтому обрыв
 * на середине лечится повторным запуском. Транзакции без категории пропускаются —
 * строки зеркалят колонку, включая её пустое состояние.
 *
 * source берётся из существующего провенанс-резолвера, а не проставляется одинаковым
 * значением: иначе режим SAFE перестал бы отличать категорию, назначенную правилом,
 * от проставленной человеком.
 *
 * Команда не блокирует транзакции, поэтому writer, изменивший категорию между чтением
 * и flush, может оставить транзакцию с лишней строкой. Отсюда порядок работы под
 * Production Gate: остановить воркеры и прогонять в тихом окне, затем verify; если
 * verify нашёл расхождение — повторить backfill. Предикат выборки совпадает с проверкой
 * expand_phase_mismatch, поэтому повторный прогон приводит состав в порядок сам,
 * без ручной правки SQL. Запускать строго в одном экземпляре: параллельные прогоны
 * упрутся в уникальный индекс (транзакция, категория) и уронят batch одного из них.
 */
#[AsCommand(
    name: 'app:cash:backfill-transaction-splits',
    description: 'Переносит cash_transaction.cashflow_category_id в строки cash_transaction_split',
)]
final class BackfillCashTransactionSplitsCommand extends Command
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionAutoRuleProvenanceResolver $provenanceResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'execute',
            null,
            InputOption::VALUE_NONE,
            'Применить изменения; без флага команда только считает объём работы',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = true === $input->getOption('execute');

        $pending = $this->countPending();
        $io->writeln(sprintf('Транзакций без строк разбивки: %d', $pending));

        if (0 === $pending) {
            $io->success('Переносить нечего.');

            return Command::SUCCESS;
        }

        if (!$execute) {
            $io->warning('Read-only режим. Повторите с --execute, чтобы перенести данные.');

            return Command::SUCCESS;
        }

        $processed = 0;
        $skipped = 0;
        $cleared = 0;
        $bySource = [
            CashTransactionSplitSource::AUTO->value => 0,
            CashTransactionSplitSource::MANUAL->value => 0,
        ];

        $io->progressStart($pending);

        while ([] !== ($ids = $this->fetchPendingIds())) {
            $handledInBatch = 0;

            foreach ($this->loadBatch($ids) as $transaction) {
                $category = $transaction->getCashflowCategory();

                if (null === $category) {
                    // Категорию сняли уже после того, как строка появилась: зеркалом
                    // пустой колонки является отсутствие строк.
                    $transaction->clearSplits();
                    ++$cleared;
                } else {
                    $source = $this->provenanceResolver->resolve($transaction)->isAutoAssigned('cashflowCategory')
                        ? CashTransactionSplitSource::AUTO
                        : CashTransactionSplitSource::MANUAL;

                    $transaction->replaceSplits([
                        new CashTransactionSplit($transaction, $category, $transaction->getAmount(), $source),
                    ]);

                    ++$bySource[$source->value];
                }

                ++$processed;
                ++$handledInBatch;
                $io->progressAdvance();
            }

            $this->entityManager->flush();
            $this->entityManager->clear();

            if (0 === $handledInBatch) {
                // Выборка что-то вернула, но обработать не удалось — например транзакцию
                // удалили между запросом и загрузкой. Без этой проверки цикл крутился бы
                // на одном и том же batch бесконечно.
                $skipped = count($ids);

                break;
            }
        }

        $io->progressFinish();

        if ($skipped > 0) {
            $io->warning(sprintf('Пропущено %d транзакций, которые не удалось загрузить. Повторите после проверки данных.', $skipped));
        }

        $io->writeln(sprintf(
            'Обработано %d транзакций: auto — %d, manual — %d, очищено — %d.',
            $processed,
            $bySource[CashTransactionSplitSource::AUTO->value],
            $bySource[CashTransactionSplitSource::MANUAL->value],
            $cleared,
        ));
        $io->note('Аудит по каждой строке не создаётся: это перенос существующего состояния, а не изменение.');

        // Пересчитываем остаток вместо доверия счётчику цикла: иначе прерванный прогон
        // отрапортовал бы успех, и автоматизация приняла бы незавершённый перенос за готовый.
        $remaining = $this->countPending();
        if ($remaining > 0) {
            $io->error(sprintf('Перенос не завершён: осталось %d транзакций. Повторите команду.', $remaining));

            return Command::FAILURE;
        }

        $io->success('Перенос завершён, несогласованных транзакций не осталось.');

        return Command::SUCCESS;
    }

    /**
     * Транзакция подлежит переносу, если её строки не повторяют колонку один в один:
     * строк нет вовсе, их больше одной, или единственная строка указывает на другую
     * категорию. Это тот же предикат, что и expand_phase_mismatch в verify-команде.
     *
     * Расширенный предикат делает команду самовосстанавливающейся. Если во время
     * прогона writer успел поменять категорию между чтением и flush, транзакция
     * получит лишнюю строку — verify это увидит, а повторный запуск backfill приведёт
     * состав в порядок вместо ручной правки SQL.
     */
    private const PENDING_PREDICATE = <<<'SQL'
        FROM cash_transaction t
        LEFT JOIN (
            SELECT cash_transaction_id, count(*) AS rows_count, min(cashflow_category_id::text) AS category
            FROM cash_transaction_split GROUP BY 1
        ) g ON g.cash_transaction_id = t.id
        WHERE (
                t.cashflow_category_id IS NOT NULL
                AND (g.cash_transaction_id IS NULL OR g.rows_count <> 1 OR g.category <> t.cashflow_category_id::text)
            )
            OR (t.cashflow_category_id IS NULL AND g.cash_transaction_id IS NOT NULL)
        SQL;

    /**
     * Транзакции батча грузятся одним запросом вместе с категорией и существующими
     * строками: иначе на 6 тысяч транзакций пришлось бы столько же find() плюс столько же
     * ленивых загрузок коллекции, и тихое окно под Production Gate выросло бы на ровном месте.
     *
     * @param list<string> $ids
     *
     * @return list<CashTransaction>
     */
    private function loadBatch(array $ids): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('t', 'c', 's')
            ->from(CashTransaction::class, 't')
            ->leftJoin('t.cashflowCategory', 'c')
            ->leftJoin('t.splits', 's')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    private function countPending(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) '.self::PENDING_PREDICATE,
        );
    }

    /**
     * @return list<string>
     */
    private function fetchPendingIds(): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT t.id '.self::PENDING_PREDICATE.' ORDER BY t.id LIMIT '.self::BATCH_SIZE,
        );
    }
}
