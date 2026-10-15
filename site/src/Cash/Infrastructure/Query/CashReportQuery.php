<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\Application\DTO\CashStatementLine;
use App\Cash\Application\DTO\MoneyAccountDailyBalanceRow;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Entity\Transaction\CashTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Выборки для отчётов Finance (выписка по счетам). Читается только через `CashFacade`.
 *
 * Запросы перенесены из `Finance\Controller\ReportTransactionsStatementController`
 * без изменения условий (этап 7.2 плана Cash).
 */
final readonly class CashReportQuery
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Строки регистра остатков за период, начиная с даты открытия счёта; пустой
     * `$accountIds` — все счета компании.
     *
     * @param list<string> $accountIds
     *
     * @return list<MoneyAccountDailyBalanceRow> по возрастанию даты
     */
    public function listDailyBalances(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds): array
    {
        Assert::uuid($companyId);
        if ($from > $to) {
            return [];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('b')
            ->from(MoneyAccountDailyBalance::class, 'b')
            ->innerJoin('b.moneyAccount', 'a')
            ->addSelect('a')
            ->where('b.company = :company')
            ->andWhere('b.date BETWEEN :from AND :to')
            ->andWhere('b.date >= a.openingBalanceDate')
            ->setParameter('company', $companyId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.date', \SortDirection::Ascending);

        if ([] !== $accountIds) {
            $qb->andWhere('a.id IN (:accountIds)')
                ->setParameter('accountIds', $accountIds);
        }

        /** @var list<MoneyAccountDailyBalance> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(static fn (MoneyAccountDailyBalance $row): MoneyAccountDailyBalanceRow => new MoneyAccountDailyBalanceRow(
            $row->getDate()->format('Y-m-d'),
            (string) $row->getMoneyAccount()->getId(),
            $row->getOpeningBalance(),
            $row->getClosingBalance(),
        ), $rows);
    }

    /**
     * Строки выписки за период: по строке на каждую строку разбивки, транзакция
     * без разбивки — одной строкой. С `$categoryId` остаются только строки этой
     * статьи, поэтому разбитая транзакция показывается суммой выбранной строки.
     *
     * Частичная гидрация работает только для транзакций, которых ещё нет в
     * identity map: повторный вызов в том же EntityManager вернёт уже загруженные
     * коллекции разбивок целиком. Контроллер выписки вызывает метод один раз за
     * запрос. Пустая строка и '0' в фильтрах значат «без фильтра» (как в контроллере).
     *
     * @return list<CashStatementLine> по времени операции, затем по id транзакции
     */
    public function listStatementLines(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $accountId,
        ?string $categoryId,
    ): array {
        Assert::uuid($companyId);

        $qb = $this->em->createQueryBuilder()
            ->select('t')
            ->from(CashTransaction::class, 't')
            ->leftJoin('t.counterparty', 'counterparty')
            ->addSelect('counterparty')
            // LEFT JOIN обязателен: транзакция без категории строк не имеет, и на INNER
            // она молча пропала бы из ведомости.
            ->leftJoin('t.splits', 'split')
            ->addSelect('split')
            ->leftJoin('split.cashflowCategory', 'category')
            ->addSelect('category')
            ->where('t.company = :company')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $companyId)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->orderBy('t.occurredAt', \SortDirection::Ascending)
            ->addOrderBy('t.id', \SortDirection::Ascending);

        if (null !== $accountId && '' !== $accountId && '0' !== $accountId) {
            $qb->andWhere('IDENTITY(t.moneyAccount) = :accountId')
                ->setParameter('accountId', $accountId);
        }

        if (null !== $categoryId && '' !== $categoryId && '0' !== $categoryId) {
            // Фильтр оставляет только совпавшие строки разбивки, поэтому коллекция
            // гидрируется частично. Для read-only отчёта это и нужно: транзакция,
            // разбитая на несколько категорий, показывается суммой выбранной строки,
            // а не всей транзакции — иначе итог ведомости не сошёлся бы с отчётом ДДС.
            $qb->andWhere('IDENTITY(split.cashflowCategory) = :categoryId')
                ->setParameter('categoryId', $categoryId);
        }

        $lines = [];
        /** @var list<CashTransaction> $transactions */
        $transactions = $qb->getQuery()->getResult();
        foreach ($transactions as $transaction) {
            $counterpartyName = $transaction->getCounterparty()?->getName();
            $description = $transaction->getDescription() ?? '';

            $splits = $transaction->getSplits();
            if ($splits->isEmpty()) {
                $lines[] = new CashStatementLine(
                    $transaction->getOccurredAt(),
                    $transaction->getExternalId(),
                    $counterpartyName,
                    $description,
                    $transaction->getDirection(),
                    $transaction->getAmount(),
                    null,
                    null,
                );

                continue;
            }

            foreach ($splits as $split) {
                $category = $split->getCashflowCategory();
                $lines[] = new CashStatementLine(
                    $transaction->getOccurredAt(),
                    $transaction->getExternalId(),
                    $counterpartyName,
                    $description,
                    $transaction->getDirection(),
                    $split->getAmount(),
                    (string) $category->getId(),
                    $category->getName(),
                );
            }
        }

        return $lines;
    }
}
