<?php

declare(strict_types=1);

namespace App\Controller\Finance;

use App\Entity\Company;
use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: '/finance/reports/cashflow-ops-check',
    name: 'report_cashflow_ops_check_index',
    methods: ['GET']
)]
final class ReportCashflowOpsCheckController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly Connection $db,
        private readonly MoneyAccountRepository $accountRepo,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // Один маршрут: если export=csv — отдаём CSV, иначе рендерим HTML
        $export = $request->query->get('export');
        if ('csv' === $export) {
            return $this->exportCsv($request);
        }

        $ctx = $this->buildContext($request);

        $trxRows = $this->fetchTrxRows($ctx);
        $reconRows = $this->fetchReconRows($ctx);

        $accounts = $this->accountRepo->findBy(['company' => $ctx['company']], ['name' => 'ASC']);
        $accountOptions = array_map(
            static fn ($a) => ['id' => $a->getId(), 'name' => $a->getName()],
            $accounts
        );

        $filters = [
            'date_from' => $ctx['from'],
            'date_to' => $ctx['to'],
            'account' => $ctx['accountId'],
        ];

        return $this->render('report/cashflow_ops_check.html.twig', [
            'filters' => $filters,
            'accounts' => $accountOptions,
            'rows_trx' => $trxRows,
            'rows_recon' => $reconRows,
        ]);
    }

    private function exportCsv(Request $request): StreamedResponse
    {
        $ctx = $this->buildContext($request);
        $part = $request->query->get('part', 'trx'); // trx | recon
        $part = \in_array($part, ['trx', 'recon'], true) ? $part : 'trx';

        $filename = sprintf(
            'cashflow-ops-check_%s_%s_%s.csv',
            $part,
            $ctx['from']->format('Y-m-d'),
            $ctx['to']->format('Y-m-d')
        );

        $response = new StreamedResponse(function () use ($part, $ctx) {
            // BOM для корректного открытия в Excel (UTF-8)
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            $delimiter = ';';

            if ('trx' === $part) {
                fputcsv($out, [
                    'Дата', 'Счёт', 'Валюта', 'Направление',
                    'Сумма (абс.)', 'Сумма (со знаком)', 'Категория',
                    'Источник', 'ExternalId',
                    'Флаг: Знак', 'Флаг: Нет категории', 'Флаг: Дубль',
                ], $delimiter);

                foreach ($this->fetchTrxRows($ctx) as $r) {
                    fputcsv($out, [
                        $r['date'] instanceof \DateTimeInterface ? $r['date']->format('Y-m-d') : $r['date'],
                        $r['account_name'],
                        $r['currency'],
                        $r['direction'],
                        number_format((float) $r['amount_abs'], 2, '.', ''),
                        number_format((float) $r['amount_signed'], 2, '.', ''),
                        $r['category_name'] ?? '',
                        $r['import_source'] ?? '',
                        $r['external_id'] ?? '',
                        (int) $r['flag_wrong_sign'],
                        (int) $r['flag_no_category'],
                        (int) $r['flag_dup'],
                    ], $delimiter);
                }
            } else {
                fputcsv($out, [
                    'Дата', 'Счёт',
                    'Открытие (баланс)',
                    'Приход (баланс)',
                    'Расход (баланс)',
                    'Закрытие (баланс)',
                    'Приход (по транз.)',
                    'Расход (по транз.)',
                    'Закрытие (по транз.)',
                    'Δ Закрытия (баланс - по транз.)',
                ], $delimiter);

                foreach ($this->fetchReconRows($ctx) as $r) {
                    fputcsv($out, [
                        $r['date'] instanceof \DateTimeInterface ? $r['date']->format('Y-m-d') : $r['date'],
                        $r['account_name'],
                        number_format((float) $r['opening'], 2, '.', ''),
                        number_format((float) $r['inflow_bal'], 2, '.', ''),
                        number_format((float) $r['outflow_bal'], 2, '.', ''),
                        number_format((float) $r['closing'], 2, '.', ''),
                        number_format((float) $r['inflow_trx'], 2, '.', ''),
                        number_format((float) $r['outflow_trx'], 2, '.', ''),
                        number_format((float) $r['closing_by_trx'], 2, '.', ''),
                        number_format((float) $r['diff_closing'], 2, '.', ''),
                    ], $delimiter);
                }
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }

    /**
     * @return array{
     *   company: Company,
     *   from: \DateTimeImmutable,
     *   to: \DateTimeImmutable,
     *   fromTs: string,
     *   toPlus1Ts: string,
     *   fromDate: string,
     *   toDate: string,
     *   accountId: ?string
     * }
     */
    private function buildContext(Request $request): array
    {
        /** @var Company $company */
        $company = $this->activeCompany->getActiveCompany();

        $from = $request->query->has('date_from')
            ? (new \DateTimeImmutable((string) $request->query->get('date_from')))->setTime(0, 0, 0)
            : (new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0);

        $to = $request->query->has('date_to')
            ? (new \DateTimeImmutable((string) $request->query->get('date_to')))->setTime(0, 0, 0)
            : (new \DateTimeImmutable('last day of this month'))->setTime(0, 0, 0);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $toPlus1 = $to->modify('+1 day');

        $fromTs = $from->format('Y-m-d H:i:s');
        $toPlus1Ts = $toPlus1->format('Y-m-d H:i:s');
        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');

        $accountId = $request->query->get('account') ?: null;

        return compact('company', 'from', 'to', 'fromTs', 'toPlus1Ts', 'fromDate', 'toDate', 'accountId');
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchTrxRows(array $ctx): array
    {
        // Флаги возвращаем как 0/1; "дубль" — гибридная логика
        $sql = <<<SQL
select
  t.id,
  t.occurred_at::date                             as date,
  acc.name                                        as account_name,
  t.currency                                      as currency,
  t.direction                                     as direction,
  t.amount::numeric(18,2)                         as amount_abs,
  case when t.direction = 'INFLOW' then t.amount else -t.amount end
                                                  as amount_signed,
  cat.name                                        as category_name,
  t.import_source                                 as import_source,
  t.external_id                                   as external_id,

  -- флаги как INT (0/1)
  case
    when (t.direction = 'INFLOW' and t.amount < 0)
      or (t.direction = 'OUTFLOW' and t.amount < 0)
    then 1 else 0 end                             as flag_wrong_sign,

  case when t.cashflow_category_id is null then 1 else 0 end
                                                  as flag_no_category,

  case
    when t.external_id is not null and t.import_source is not null then
      case when (count(*) over (partition by t.company_id, t.import_source, t.external_id)) > 1
           then 1 else 0 end
    else
      case when (count(*) over (
                  partition by
                    t.company_id,
                    t.occurred_at::date,
                    t.money_account_id,
                    abs(t.amount),
                    t.direction,
                    t.currency
                )) > 1
           then 1 else 0 end
  end                                             as flag_dup

from cash_transaction t
left join money_account acc on acc.id = t.money_account_id
left join cashflow_categories cat on cat.id = t.cashflow_category_id
where t.company_id = :company
  and t.occurred_at >= :from_ts
  and t.occurred_at <  :to_plus1_ts
  and (:acc_id::uuid is null or t.money_account_id = :acc_id::uuid)
order by t.occurred_at asc, t.id asc
SQL;

        return $this->db->fetchAllAssociative($sql, [
            'company' => $ctx['company']->getId(),
            'from_ts' => $ctx['fromTs'],
            'to_plus1_ts' => $ctx['toPlus1Ts'],
            'acc_id' => $ctx['accountId'],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchReconRows(array $ctx): array
    {
        $sql = <<<SQL
with trx as (
  select
    t.money_account_id,
    t.occurred_at::date                                         as date,
    sum(case when t.direction = 'INFLOW'  then t.amount else 0 end)::numeric(18,2)    as inflow_trx,
    sum(case when t.direction = 'OUTFLOW' then t.amount else 0 end)::numeric(18,2)    as outflow_trx_abs
  from cash_transaction t
  where t.company_id = :company
    and t.occurred_at >= :from_ts
    and t.occurred_at <  :to_plus1_ts
    and (:acc_id::uuid is null or t.money_account_id = :acc_id::uuid)
  group by t.money_account_id, t.occurred_at::date
),
dbal as (
  select
    b.money_account_id,
    b.date,
    b.opening_balance::numeric(18,2)  as opening,
    b.inflow::numeric(18,2)           as inflow_bal,
    b.outflow::numeric(18,2)          as outflow_bal,
    b.closing_balance::numeric(18,2)  as closing
  from money_account_daily_balance b
  where b.company_id = :company
    and b.date >= :from_date::date
    and b.date <= :to_date::date
    and (:acc_id::uuid is null or b.money_account_id = :acc_id::uuid)
)
select
  acc.name                                  as account_name,
  dbal.date                                  as date,
  dbal.opening                               as opening,
  dbal.inflow_bal                            as inflow_bal,
  dbal.outflow_bal                           as outflow_bal,
  dbal.closing                               as closing,
  coalesce(trx.inflow_trx, 0)                as inflow_trx,
  coalesce(trx.outflow_trx_abs, 0)           as outflow_trx,
  (dbal.opening + coalesce(trx.inflow_trx,0) - coalesce(trx.outflow_trx_abs,0))::numeric(18,2)
                                            as closing_by_trx,
  (dbal.closing - (dbal.opening + coalesce(trx.inflow_trx,0) - coalesce(trx.outflow_trx_abs,0)))::numeric(18,2)
                                            as diff_closing
from dbal
left join trx on trx.money_account_id = dbal.money_account_id and trx.date = dbal.date
left join money_account acc on acc.id = dbal.money_account_id
order by dbal.date asc, account_name asc
SQL;

        return $this->db->fetchAllAssociative($sql, [
            'company' => $ctx['company']->getId(),
            'from_ts' => $ctx['fromTs'],
            'to_plus1_ts' => $ctx['toPlus1Ts'],
            'from_date' => $ctx['fromDate'],
            'to_date' => $ctx['toDate'],
            'acc_id' => $ctx['accountId'],
        ]);
    }
}
