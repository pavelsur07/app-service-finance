<?php

declare(strict_types=1);

namespace App\Settlement\Query;

use App\Company\Facade\CounterpartyFacade;
use App\Settlement\Application\DTO\SettlementBalances;
use App\Settlement\Application\DTO\SettlementCardPage;
use App\Settlement\Application\DTO\SettlementCounterpartyRow;
use App\Settlement\Application\DTO\SettlementMovementRow;
use App\Settlement\Application\DTO\SettlementReportFilter;
use App\Settlement\Application\DTO\SettlementReportPage;
use App\Settlement\Application\DTO\SettlementReportTotals;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementSourceType;
use App\Shared\Domain\Exception\MoneyOverflowException;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Connection;

/** Page, opening, turnover and totals come from one PostgreSQL statement snapshot. */
final readonly class SettlementReportQuery
{
    public function __construct(private Connection $connection, private CounterpartyFacade $counterparties)
    {
    }

    public function report(string $companyId, SettlementReportFilter $filter): SettlementReportPage
    {
        $data = $this->read($companyId, $filter, null);
        $rawRows = $data['rows'];
        $labels = $this->counterparties->getLabelsByIds($companyId, array_column($rawRows, 'counterparty_id'));
        $rows = [];
        foreach ($rawRows as $row) {
            $rows[] = $this->party($row, $labels[$row['counterparty_id']] ?? 'Контрагент недоступен');
        }
        $totals = $data['totals'];

        return new SettlementReportPage($rows, $data['total'], new SettlementReportTotals($this->balances($totals, 'opening'), $this->balances($totals, 'increases'), $this->balances($totals, 'decreases'), $this->balances($totals, 'closing'), $this->money($totals['overdue']), $this->money($totals['without_due_date'])));
    }

    public function card(string $companyId, string $counterpartyId, SettlementReportFilter $filter): SettlementCardPage
    {
        $data = $this->read($companyId, $filter, $counterpartyId);
        $labels = $this->counterparties->getLabelsByIds($companyId, [$counterpartyId]);
        $summary = $this->party(['counterparty_id' => $counterpartyId] + $data['totals'], $labels[$counterpartyId] ?? 'Контрагент недоступен');
        $rows = [];
        foreach ($data['movements'] as $movement) {
            $rows[] = new SettlementMovementRow($movement['document_id'], $movement['item_id'], new \DateTimeImmutable($movement['effective_date'], new \DateTimeZone('Europe/Moscow')), SettlementBucket::from($movement['bucket']), $this->money($movement['delta']), SettlementSourceType::from($movement['source_type']), $movement['source_id'], $movement['reason']);
        }

        return new SettlementCardPage($rows, $data['movement_total'], $summary);
    }

    /**
     * @return array{rows:list<array{counterparty_id:string,balances:array<string,array<string,string>>,overdue:string,without_due_date:string}>,total:int,totals:array{balances:array<string,array<string,string>>,overdue:string,without_due_date:string},movements:list<array{document_id:string,item_id:string,effective_date:string,bucket:string,delta:string,source_type:string,source_id:?string,reason:string}>,movement_total:int}
     */
    private function read(string $companyId, SettlementReportFilter $filter, ?string $party): array
    {
        $parameters = ['company' => $companyId, 'currency' => $filter->currency, 'from' => $filter->from->format('Y-m-d'), 'to' => $filter->to->format('Y-m-d')];
        $includeMovements = null !== $party;
        $party ??= $filter->counterpartyId;
        $partyWhere = '';
        if (null !== $party) {
            $partyWhere = ' AND i.counterparty_id=:party';
            $parameters['party'] = $party;
        }
        $limit = $filter->perPage;
        $offset = ($filter->page - 1) * $limit;
        $sql = <<<'SQL'
WITH journal AS (
 SELECT i.counterparty_id,i.id AS item_id,i.due_date,m.document_id,m.bucket,m.effective_date,m.delta_amount_minor AS delta,
 d.kind,d.event_sequence,d.source_type,d.source_id,d.reason,m.id
 FROM settlement_movements m
 JOIN settlement_documents d ON d.company_id=m.company_id AND d.id=m.document_id
 JOIN settlement_items i ON i.company_id=m.company_id AND i.id=m.item_id
 WHERE m.company_id=:company AND m.delta_currency=:currency AND i.amount_currency=:currency
 AND d.status='posted' AND m.effective_date<=:to
SQL;
        $sql .= $partyWhere.<<<'SQL'

), item_values AS (
 SELECT counterparty_id,item_id,due_date,bucket,
 COALESCE(SUM(delta) FILTER(WHERE effective_date<:from OR kind='opening'),0) AS opening,
 COALESCE(SUM(delta) FILTER(WHERE effective_date>=:from AND kind<>'opening' AND delta>0),0) AS increases,
 COALESCE(-SUM(delta) FILTER(WHERE effective_date>=:from AND kind<>'opening' AND delta<0),0) AS decreases,
 SUM(delta) AS closing
 FROM journal GROUP BY counterparty_id,item_id,due_date,bucket
), party_values AS (
 SELECT counterparty_id,bucket,SUM(opening) AS opening,SUM(increases) AS increases,SUM(decreases) AS decreases,SUM(closing) AS closing,
 COALESCE(SUM(closing) FILTER(WHERE bucket IN ('receivable','payable') AND due_date<:to AND closing>0),0) AS overdue,
 COALESCE(SUM(closing) FILTER(WHERE bucket IN ('receivable','payable') AND due_date IS NULL AND closing>0),0) AS without_due_date
 FROM item_values GROUP BY counterparty_id,bucket
), party_rows AS (
 SELECT counterparty_id,jsonb_object_agg(bucket,jsonb_build_object('opening',opening::text,'increases',increases::text,'decreases',decreases::text,'closing',closing::text)) AS balances,
 SUM(overdue)::text AS overdue,SUM(without_due_date)::text AS without_due_date
 FROM party_values GROUP BY counterparty_id
), total_buckets AS (
 SELECT bucket,SUM(opening)::text AS opening,SUM(increases)::text AS increases,SUM(decreases)::text AS decreases,SUM(closing)::text AS closing
 FROM party_values GROUP BY bucket
)
SELECT
 (SELECT COALESCE(jsonb_agg(to_jsonb(p) ORDER BY p.counterparty_id),'[]'::jsonb) FROM (SELECT counterparty_id,balances,overdue,without_due_date FROM party_rows ORDER BY counterparty_id LIMIT %LIMIT% OFFSET %OFFSET%) p) AS rows,
 (SELECT COUNT(*) FROM party_rows) AS total,
 jsonb_build_object('balances',(SELECT COALESCE(jsonb_object_agg(bucket,jsonb_build_object('opening',opening,'increases',increases,'decreases',decreases,'closing',closing)),'{}'::jsonb) FROM total_buckets),
 'overdue',(SELECT COALESCE(SUM(overdue::numeric),0)::text FROM party_rows),
 'without_due_date',(SELECT COALESCE(SUM(without_due_date::numeric),0)::text FROM party_rows)) AS totals,
 %MOVEMENTS% AS movements,
 %MOVEMENT_TOTAL% AS movement_total,
 (SELECT start_date FROM settlement_books WHERE company_id=:company) AS start_date
SQL;
        $movementSql = "(SELECT COALESCE(jsonb_agg(to_jsonb(v) ORDER BY v.effective_date,v.event_sequence,v.item_id,v.bucket),'[]'::jsonb) FROM (SELECT document_id,item_id,effective_date,bucket,delta::text AS delta,source_type,source_id,reason,event_sequence,id FROM journal WHERE effective_date>=:from ORDER BY effective_date,event_sequence,item_id,bucket LIMIT $limit OFFSET $offset) v)";
        $sql = strtr($sql, ['%LIMIT%' => (string) $limit, '%OFFSET%' => (string) $offset, '%MOVEMENTS%' => $includeMovements ? $movementSql : "'[]'::jsonb", '%MOVEMENT_TOTAL%' => $includeMovements ? '(SELECT COUNT(*) FROM journal WHERE effective_date>=:from)' : '0']);
        $row = $this->connection->fetchAssociative($sql, $parameters);
        if (false === $row) {
            throw new \LogicException('Missing aggregate row.');
        }
        if (null !== $row['start_date'] && $parameters['from'] < $row['start_date']) {
            throw new \DomainException('Период начинается раньше даты начала учёта взаиморасчётов.');
        }

        return ['rows' => json_decode($row['rows'], true, 512, \JSON_THROW_ON_ERROR), 'total' => (int) $row['total'], 'totals' => json_decode($row['totals'], true, 512, \JSON_THROW_ON_ERROR), 'movements' => json_decode($row['movements'], true, 512, \JSON_THROW_ON_ERROR), 'movement_total' => (int) $row['movement_total']];
    }

    /** @param array{counterparty_id:string,balances:array<string,array<string,string>>,overdue:string,without_due_date:string} $row */
    private function party(array $row, string $name): SettlementCounterpartyRow
    {
        return new SettlementCounterpartyRow($row['counterparty_id'], $this->balances($row, 'opening'), $this->balances($row, 'increases'), $this->balances($row, 'decreases'), $this->balances($row, 'closing'), $this->money($row['overdue']), $this->money($row['without_due_date']), $name);
    }

    /** @param array{balances:array<string,array<string,string>>} $row */
    private function balances(array $row, string $column): SettlementBalances
    {
        $values = [];
        foreach (SettlementBucket::cases() as $bucket) {
            $values[] = $this->money($row['balances'][$bucket->value][$column] ?? '0');
        }

        return new SettlementBalances(...$values);
    }

    private function money(string $minor): Money
    {
        if (!preg_match('/^-?\d+$/D', $minor) || bccomp($minor, (string) \PHP_INT_MAX, 0) > 0 || bccomp($minor, (string) \PHP_INT_MIN, 0) < 0) {
            throw new MoneyOverflowException('Итог отчёта превышает допустимую сумму.');
        }

        return Money::fromMinor((int) $minor, 'RUB');
    }
}
