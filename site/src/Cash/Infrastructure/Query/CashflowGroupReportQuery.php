<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\Application\DTO\CashflowGroupReportAmount;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Суммы строк разбивки компаний группы за период. Охват тот же, что у
 * CashReportQuery::listCashflowReportRows (отчёт ДДС компании): не удалённые транзакции,
 * дата операции (`occurred_at` — date) в периоде включительно, знак — по направлению.
 */
final readonly class CashflowGroupReportQuery
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param list<string> $companyIds компании одной группы консолидации
     *
     * @return list<CashflowGroupReportAmount>
     */
    public function __invoke(array $companyIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        Assert::notEmpty($companyIds);
        Assert::allUuid($companyIds);

        /** @var list<array{company_id: string, category_id: string, currency: string, counterparty_inn: string|null, amount: string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT t.company_id, s.cashflow_category_id AS category_id, t.currency, cp.inn AS counterparty_inn,
                       SUM(CASE WHEN t.direction = 'OUTFLOW' THEN -ABS(s.amount) ELSE ABS(s.amount) END) AS amount
                FROM cash_transaction t
                JOIN cash_transaction_split s ON s.cash_transaction_id = t.id
                LEFT JOIN counterparty cp ON cp.id = t.counterparty_id
                WHERE t.company_id IN (:companyIds)
                  AND t.occurred_at BETWEEN :from AND :to
                  AND t.deleted_at IS NULL
                GROUP BY t.company_id, s.cashflow_category_id, t.currency, cp.inn
                SQL,
            [
                'companyIds' => $companyIds,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            ['companyIds' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): CashflowGroupReportAmount => new CashflowGroupReportAmount(
            (string) $row['company_id'],
            (string) $row['category_id'],
            $row['currency'],
            null === $row['counterparty_inn'] || '' === trim($row['counterparty_inn']) ? null : trim($row['counterparty_inn']),
            bcadd((string) $row['amount'], '0', 2),
        ), $rows);
    }
}
