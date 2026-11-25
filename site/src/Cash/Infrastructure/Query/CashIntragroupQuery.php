<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\Application\DTO\CashflowAccountStatus;
use App\Cash\Application\DTO\IntragroupTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

final readonly class CashIntragroupQuery
{
    // В PHP и шаблоны попадает только суффикс номера.
    private const ACCOUNT_LABEL = "concat_ws(' ', a.name, '****' || right(nullif(regexp_replace(a.account_number, '[^0-9]', '', 'g'), ''), 4))";

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<string> $companyIds компании одной группы
     * @param list<string> $extraIds ноги ручных решений за любыми датами
     *
     * @return array<string, IntragroupTransaction> по id транзакции
     */
    public function transactions(array $companyIds, \DateTimeImmutable $from, \DateTimeImmutable $to, array $extraIds = []): array
    {
        Assert::notEmpty($companyIds);
        Assert::allUuid($companyIds);
        Assert::allUuid($extraIds);

        $label = self::ACCOUNT_LABEL;
        /** @var iterable<array{id: string, company_id: string, company_inn: ?string, counterparty_inn: string, direction: string, occurred_at: string, amount: string, account_label: string, splits: string}> $rows */
        $rows = $this->connection->iterateAssociative(
            <<<SQL
                SELECT t.id, t.company_id, NULLIF(trim(c.inn), '') AS company_inn,
                       trim(cp.inn) AS counterparty_inn, t.direction, t.occurred_at, ABS(t.amount) AS amount,
                       $label AS account_label, parts.splits
                FROM cash_transaction t
                JOIN companies c ON c.id = t.company_id
                JOIN counterparty cp ON cp.id = t.counterparty_id AND cp.company_id = t.company_id
                JOIN money_account a ON a.id = t.money_account_id AND a.company_id = t.company_id
                JOIN LATERAL (
                    SELECT json_object_agg(s.cashflow_category_id, ABS(s.amount)::text) AS splits
                    FROM cash_transaction_split s
                    WHERE s.cash_transaction_id = t.id AND s.company_id = t.company_id
                    HAVING count(*) > 0
                ) parts ON true
                WHERE t.company_id IN (:companyIds) AND t.deleted_at IS NULL AND t.currency = 'RUB'
                  AND (t.occurred_at BETWEEN :from AND :to OR t.id IN (:extraIds))
                  AND trim(cp.inn) <> ''
                  AND EXISTS (
                      SELECT 1 FROM companies peer
                      WHERE peer.id IN (:companyIds) AND peer.id <> t.company_id AND trim(peer.inn) = trim(cp.inn)
                  )
                ORDER BY t.occurred_at, t.id
                SQL,
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'companyIds' => $companyIds, 'extraIds' => $extraIds],
            ['companyIds' => ArrayParameterType::STRING, 'extraIds' => ArrayParameterType::STRING],
        );

        $byId = [];
        foreach ($rows as $row) {
            /** @var array<string, string> $splits */
            $splits = json_decode($row['splits'], true, flags: \JSON_THROW_ON_ERROR);
            $byId[$row['id']] = new IntragroupTransaction(
                $row['id'], $row['company_id'], $row['company_inn'], $row['counterparty_inn'],
                CashDirection::from($row['direction']), new \DateTimeImmutable($row['occurred_at']),
                Money::fromString($row['amount'], 'RUB'), $row['account_label'], $splits,
            );
        }

        return $byId;
    }

    /**
     * @param list<string> $companyIds компании одной группы
     *
     * @return list<CashflowAccountStatus>
     */
    public function accounts(array $companyIds): array
    {
        Assert::notEmpty($companyIds);
        Assert::allUuid($companyIds);

        $label = self::ACCOUNT_LABEL;
        /** @var list<array{id: string, company_id: string, account_label: string, last_date: ?string, lag_days: int|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT a.id, a.company_id, $label AS account_label, latest.last_date,
                       max(latest.last_date) OVER () - latest.last_date AS lag_days
                FROM money_account a
                LEFT JOIN LATERAL (
                    SELECT t.occurred_at AS last_date FROM cash_transaction t
                    WHERE t.company_id = a.company_id AND t.money_account_id = a.id AND t.deleted_at IS NULL
                    ORDER BY t.occurred_at DESC LIMIT 1
                ) latest ON true
                WHERE a.company_id IN (:companyIds) AND a.is_active AND a.currency = 'RUB'
                ORDER BY a.company_id, a.sort_order, a.name, a.id
                SQL,
            ['companyIds' => $companyIds],
            ['companyIds' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): CashflowAccountStatus => new CashflowAccountStatus(
            $row['id'], $row['company_id'], $row['account_label'], $row['last_date'], $row['lag_days'] > 3,
        ), $rows);
    }
}
