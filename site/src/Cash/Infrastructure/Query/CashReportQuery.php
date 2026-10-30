<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\Application\DTO\CashflowReportRow;
use App\Cash\Application\DTO\CashflowReportRowFilter;
use App\Cash\Application\DTO\CashStatementLine;
use App\Cash\Application\DTO\MoneyAccountDailyBalanceRow;
use App\Cash\Application\DTO\MoneyAccountOpeningBalance;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Выборки для отчётов вне модуля Cash. Читается только через `CashFacade`.
 *
 * Запросы перенесены без изменения условий: выписка — из
 * `Finance\Controller\ReportTransactionsStatementController` (этап 7.2 плана Cash),
 * отчёт ДДС — из `Report\Cashflow\CashflowReportBuilder`.
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

    /**
     * Строки отчёта ДДС за период: по строке разбивки. Порядок не задан, как и раньше.
     *
     * @return list<CashflowReportRow>
     */
    public function listCashflowReportRows(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        CashflowReportRowFilter $filter,
    ): array {
        Assert::uuid($companyId);

        $qb = $this->em->createQueryBuilder()
            ->from(CashTransaction::class, 't')
            ->select(
                'IDENTITY(split.cashflowCategory) AS category',
                'IDENTITY(t.projectDirection) AS project_id',
                'project.name AS project_name',
                't.responsibilityCenterId AS responsibility_center_id',
                't.direction',
                'split.amount',
                't.currency',
                't.occurredAt',
            )
            // LEFT JOIN, а не INNER: транзакция без категории строк не имеет и в отчёт
            // не попадает по проверке категории в отчёте — ровно как раньше по пустой колонке.
            ->leftJoin('t.splits', 'split')
            ->leftJoin('t.projectDirection', 'project')
            ->where('t.company = :company')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $companyId)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59));

        if ($filter->dashboardScope) {
            // Тот же охват, что у CashTransactionRepository::sumGrossTurnoverByPeriodExcludeTransfers().
            $qb->innerJoin('split.cashflowCategory', 'cashflowCategory')
                ->andWhere('t.currency = :dashboardCurrency')
                ->andWhere('t.isTransfer = :dashboardTransfer')
                ->andWhere('cashflowCategory.flowKind != :technicalFlowKind')
                ->setParameter('dashboardCurrency', $filter->dashboardCurrency)
                ->setParameter('dashboardTransfer', false)
                ->setParameter('technicalFlowKind', CashflowFlowKind::TECHNICAL);

            if (null !== $filter->dashboardFlowKind) {
                $qb->andWhere('cashflowCategory.flowKind = :dashboardFlowKind')
                    ->andWhere('(cashflowCategory.systemCode IS NULL OR cashflowCategory.systemCode NOT IN (:dashboardUnallocatedCodes))')
                    ->setParameter('dashboardFlowKind', $filter->dashboardFlowKind)
                    ->setParameter('dashboardUnallocatedCodes', [
                        CashflowCategory::CODE_UNALLOCATED,
                        CashflowCategory::SYSTEM_UNALLOCATED,
                    ]);
            }
        }

        if (null !== $filter->currency) {
            $qb->andWhere('t.currency = :companyCurrency')
                ->setParameter('companyCurrency', $filter->currency);
        }

        if (null !== $filter->projectDirectionIds) {
            $qb->andWhere('IDENTITY(t.projectDirection) IN (:projectDirectionIds)')
                ->setParameter('projectDirectionIds', $filter->projectDirectionIds);
        }

        if (null !== $filter->responsibilityCenterIds) {
            $qb->andWhere('t.responsibilityCenterId IN (:responsibilityCenterIds)')
                ->setParameter('responsibilityCenterIds', $filter->responsibilityCenterIds);
        } elseif (null !== $filter->responsibilityCenterId) {
            $qb->andWhere('t.responsibilityCenterId = :responsibilityCenterId')
                ->setParameter('responsibilityCenterId', $filter->responsibilityCenterId);
        }

        /** @var list<array{category: string|null, project_id: string|null, project_name: string|null, responsibility_center_id: string|null, direction: CashDirection|string, amount: string|null, currency: string, occurredAt: \DateTimeImmutable}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static fn (array $row): CashflowReportRow => new CashflowReportRow(
            null === $row['category'] ? null : (string) $row['category'],
            null === $row['project_id'] ? null : (string) $row['project_id'],
            $row['project_name'],
            $row['responsibility_center_id'],
            $row['direction'] instanceof CashDirection ? $row['direction'] : CashDirection::from($row['direction']),
            $row['amount'],
            $row['currency'],
            $row['occurredAt'],
        ), $rows);
    }

    /**
     * Входящий остаток каждого счёта на начало дня `$date` в порядке `$accounts`:
     * входящий остаток строки регистра за этот день; иначе исходящий остаток
     * последней строки до него, но не раньше даты открытия счёта; иначе входящий
     * остаток самого счёта. Один запрос вместо двух на счёт, по строке на счёт.
     *
     * @param list<MoneyAccount> $accounts
     *
     * @return list<MoneyAccountOpeningBalance>
     */
    public function listOpeningBalancesAtDate(string $companyId, \DateTimeImmutable $date, array $accounts): array
    {
        Assert::uuid($companyId);
        if ([] === $accounts) {
            return [];
        }

        $date = $date->setTime(0, 0);
        /** @var list<MoneyAccountDailyBalance> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('b', 'a')
            ->from(MoneyAccountDailyBalance::class, 'b')
            ->innerJoin('b.moneyAccount', 'a')
            ->where('b.company = :company')
            ->andWhere('a.id IN (:accountIds)')
            // Только последняя подходящая строка счёта — без истории регистра целиком.
            ->andWhere(sprintf(
                'b.date = (SELECT MAX(b2.date) FROM %s b2 WHERE b2.moneyAccount = a AND b2.company = :company'
                .' AND (b2.date = :date OR (b2.date < :date AND b2.date >= a.openingBalanceDate)))',
                MoneyAccountDailyBalance::class,
            ))
            ->setParameter('company', $companyId)
            ->setParameter('accountIds', array_map(static fn (MoneyAccount $a): string => (string) $a->getId(), $accounts))
            ->setParameter('date', $date)
            ->orderBy('a.id', \SortDirection::Ascending)
            ->addOrderBy('b.date', \SortDirection::Descending)
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($rows as $row) {
            $accountId = (string) $row->getMoneyAccount()->getId();
            $latest[$accountId] ??= $row;
        }

        $result = [];
        foreach ($accounts as $account) {
            $accountId = (string) $account->getId();
            $row = $latest[$accountId] ?? null;
            $opening = match (true) {
                null === $row => $account->getOpeningBalance(),
                $row->getDate()->format('Y-m-d') === $date->format('Y-m-d') => $row->getOpeningBalance(),
                default => $row->getClosingBalance(),
            };
            $result[] = new MoneyAccountOpeningBalance($accountId, $account->getCurrency(), $opening);
        }

        return $result;
    }
}
