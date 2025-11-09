<?php

namespace App\Marketplace\Wildberries\Service;

use App\Entity\Company;
use App\Marketplace\Wildberries\Adapter\WildberriesStatisticsV5Client;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class WildberriesReportDetailImporter
{
    private const BATCH_SIZE = 800; // баланс скорость/память

    public function __construct(
        private readonly WildberriesStatisticsV5Client $client,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function importPeriodForCompany(
        Company $company,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        string $period = 'weekly',
    ): void {
        $this->doImport($company, $dateFrom, $dateTo, $period);
    }

    /**
     * Импорт детализации за интервал дат (WB v5, period=daily|weekly).
     *
     * @return int Количество обработанных строк
     */
    public function import(
        Company $company,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        string $period = 'daily',
    ): int {
        return $this->doImport($company, $dateFrom, $dateTo, $period);
    }

    private function doImport(
        Company $company,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        string $period,
    ): int {
        $dateFrom = $this->normalizeDate($dateFrom);
        $dateTo = $this->normalizeDate($dateTo);

        $companyId = $company->getId();
        if (null === $companyId) {
            throw new \RuntimeException('Cannot import WB report details for a company without identifier');
        }
        $companyId = (string) $companyId;

        /** @var Connection $conn */
        $conn = $this->em->getConnection();

        $this->logger->info('[WB:ReportDetail] Start', [
            'company' => $companyId,
            'from' => $dateFrom->format(\DATE_ATOM),
            'to' => $dateTo->format(\DATE_ATOM),
            'period' => $period,
        ]);

        $processed = 0;
        $currentImportId = Uuid::uuid4()->toString(); // единый import_id на запуск
        $nowIso = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            foreach ($this->iterateDateWindows($dateFrom, $dateTo, $period) as [$windowFrom, $windowTo]) {
                $rrdIdCursor = 0;

                while (true) {
                    // 1 страница от WB
                    $page = $this->client->fetchReportDetailByPeriod(
                        $company,
                        $windowFrom,
                        $windowTo,
                        $rrdIdCursor,
                        $period
                    );

                    if (empty($page)) {
                        break;
                    }

                    $rows = [];
                    $rowsCount = 0;
                    $maxInBatch = $rrdIdCursor;

                    foreach ($page as $r) {
                        if (!isset($r['rrd_id'])) {
                            // строка без ключа — пропускаем
                            continue;
                        }

                        $rrd = (int) $r['rrd_id'];
                        if ($rrd > $maxInBatch) {
                            $maxInBatch = $rrd;
                        }

                        $rows[] = $this->mapRowToDb($r, $companyId, $currentImportId, $nowIso);
                        ++$rowsCount;

                        if ($rowsCount >= self::BATCH_SIZE) {
                            $this->upsertChunk($conn, $rows);
                            $processed += $rowsCount;
                            $rows = [];
                            $rowsCount = 0;
                        }
                    }

                    if ($rowsCount > 0) {
                        $this->upsertChunk($conn, $rows);
                        $processed += $rowsCount;
                    }

                    $this->logger->info('[WB:ReportDetail] Page done', [
                        'company' => $companyId,
                        'window_from' => $windowFrom->format(\DATE_ATOM),
                        'window_to' => $windowTo->format(\DATE_ATOM),
                        'count' => \count($page),
                        'processed' => $processed,
                        'next_rrd' => $maxInBatch,
                    ]);

                    if ($maxInBatch === $rrdIdCursor) {
                        // курсор не сдвинулся — выходим, чтобы не зациклиться
                        break;
                    }
                    $rrdIdCursor = $maxInBatch;
                }
            }

            $this->logger->info('[WB:ReportDetail] Finished', [
                'company' => $companyId,
                'processed' => $processed,
                'from' => $dateFrom->format(\DATE_ATOM),
                'to' => $dateTo->format(\DATE_ATOM),
                'period' => $period,
                'import_id' => $currentImportId,
            ]);

            return $processed;
        } catch (\Throwable $e) {
            $this->logger->error('[WB:ReportDetail] Failed', [
                'company' => $companyId,
                'from' => $dateFrom->format(\DATE_ATOM),
                'to' => $dateTo->format(\DATE_ATOM),
                'period' => $period,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /** Трансформация WB-строки -> плоский массив для UPSERT. */
    private function mapRowToDb(array $r, string $companyId, string $importId, string $nowIso): array
    {
        $saleDt = $this->formatDbTs($r['sale_dt'] ?? null);
        $rrDt = $this->formatDbTs($r['rr_dt'] ?? null);
        $orderDt = $this->formatDbTs($r['order_dt'] ?? null);

        return [
            'id' => Uuid::uuid4()->toString(),
            'company_id' => $companyId,
            'import_id' => $importId,
            'rrd_id' => (int) $r['rrd_id'],
            'realizationreport_id' => isset($r['realizationreport_id']) ? (int) $r['realizationreport_id'] : null,

            'sale_dt' => $saleDt,
            'rr_dt' => $rrDt,
            'order_dt' => $orderDt,

            'nm_id' => isset($r['nm_id']) ? (int) $r['nm_id'] : null,
            'barcode' => $r['barcode'] ?? null,
            'subject_name' => $r['subject_name'] ?? null,
            'brand_name' => $r['brand_name'] ?? null,

            'retail_price' => $this->toDecimalString($r['retail_price'] ?? null),
            'retail_price_with_disc_rub' => $this->toDecimalString($r['retail_price_with_disc_rub'] ?? null),
            'ppvz_sales_commission' => $this->toDecimalString($r['ppvz_sales_commission'] ?? null),
            'ppvz_for_pay' => $this->toDecimalString($r['ppvz_for_pay'] ?? null),
            'delivery_rub' => $this->toDecimalString($r['delivery_rub'] ?? null),
            'storage_fee' => $this->toDecimalString($r['storage_fee'] ?? null),
            'acceptance' => $this->toDecimalString($r['acceptance'] ?? null),
            'penalty' => $this->toDecimalString($r['penalty'] ?? null),
            'acquiring_fee' => $this->toDecimalString($r['acquiring_fee'] ?? null),

            'site_country' => $r['site_country'] ?? null,
            'supplier_oper_name' => $r['supplier_oper_name'] ?? null,
            'doc_type_name' => $r['doc_type_name'] ?? null,

            'status_updated_at' => $rrDt ?: $nowIso,
            'updated_at' => $nowIso,
            'created_at' => $nowIso,

            // jsonb RAW
            'raw' => \json_encode($r, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Батчевый UPSERT: INSERT ... ON CONFLICT (company_id, rrd_id) DO UPDATE SET ...
     * ВАЖНО: в массиве $params ключи БЕЗ двоеточия, двоеточие только в SQL.
     */
    private function upsertChunk(Connection $conn, array $rows): void
    {
        if (!$rows) {
            return;
        }

        $columns = [
            'id', 'company_id', 'import_id', 'rrd_id', 'realizationreport_id',
            'sale_dt', 'rr_dt', 'order_dt',
            'nm_id', 'barcode', 'subject_name', 'brand_name',
            'retail_price', 'retail_price_with_disc_rub', 'ppvz_sales_commission', 'ppvz_for_pay',
            'delivery_rub', 'storage_fee', 'acceptance', 'penalty', 'acquiring_fee',
            'site_country', 'supplier_oper_name', 'doc_type_name',
            'status_updated_at', 'updated_at', 'created_at', 'raw',
        ];

        $placeholders = [];
        $params = [];
        $i = 0;

        foreach ($rows as $row) {
            $rowPh = [];
            foreach ($columns as $col) {
                $name = 'p'.$i++;          // ключ без двоеточия
                $rowPh[] = ':'.$name;         // плейсхолдер с двоеточием
                $params[$name] = $row[$col] ?? null;
            }
            $placeholders[] = '('.\implode(',', $rowPh).')';
        }

        $sql = <<<SQL
INSERT INTO wildberries_report_details
  ("id","company_id","import_id","rrd_id","realizationreport_id",
   "sale_dt","rr_dt","order_dt",
   "nm_id","barcode","subject_name","brand_name",
   "retail_price","retail_price_with_disc_rub","ppvz_sales_commission","ppvz_for_pay",
   "delivery_rub","storage_fee","acceptance","penalty","acquiring_fee",
   "site_country","supplier_oper_name","doc_type_name",
   "status_updated_at","updated_at","created_at","raw")
VALUES
  %s
ON CONFLICT ("company_id","rrd_id") DO UPDATE SET
   "import_id"                    = EXCLUDED."import_id",
   "realizationreport_id"         = EXCLUDED."realizationreport_id",
   "sale_dt"                      = EXCLUDED."sale_dt",
   "rr_dt"                        = EXCLUDED."rr_dt",
   "order_dt"                     = EXCLUDED."order_dt",
   "nm_id"                        = EXCLUDED."nm_id",
   "barcode"                      = EXCLUDED."barcode",
   "subject_name"                 = EXCLUDED."subject_name",
   "brand_name"                   = EXCLUDED."brand_name",
   "retail_price"                 = EXCLUDED."retail_price",
   "retail_price_with_disc_rub"   = EXCLUDED."retail_price_with_disc_rub",
   "ppvz_sales_commission"        = EXCLUDED."ppvz_sales_commission",
   "ppvz_for_pay"                 = EXCLUDED."ppvz_for_pay",
   "delivery_rub"                 = EXCLUDED."delivery_rub",
   "storage_fee"                  = EXCLUDED."storage_fee",
   "acceptance"                   = EXCLUDED."acceptance",
   "penalty"                      = EXCLUDED."penalty",
   "acquiring_fee"                = EXCLUDED."acquiring_fee",
   "site_country"                 = EXCLUDED."site_country",
   "supplier_oper_name"           = EXCLUDED."supplier_oper_name",
   "doc_type_name"                = EXCLUDED."doc_type_name",
   "status_updated_at"            = EXCLUDED."status_updated_at",
   "updated_at"                   = EXCLUDED."updated_at",
   "raw"                          = EXCLUDED."raw"
SQL;

        $sql = \sprintf($sql, \implode(',', $placeholders));
        $conn->executeStatement($sql, $params);
    }

    /** Деление исходного интервала на окна без перекрытия. */
    private function iterateDateWindows(\DateTimeImmutable $from, \DateTimeImmutable $to, string $period): iterable
    {
        $from = $this->normalizeDate($from);
        $to = $this->normalizeDate($to);

        $chunkSize = $this->windowSizeDays($period);
        $cursor = $from;

        while ($cursor <= $to) {
            $windowEnd = $this->calculateWindowEnd($cursor, $to, $chunkSize);
            yield [$cursor, $windowEnd];
            $cursor = $windowEnd->add(new \DateInterval('P1D'));
        }
    }

    private function windowSizeDays(string $period): int
    {
        return match ($period) {
            'weekly' => 7,
            default => 1,
        };
    }

    private function calculateWindowEnd(\DateTimeImmutable $start, \DateTimeImmutable $globalEnd, int $chunkSizeDays): \DateTimeImmutable
    {
        if ($chunkSizeDays <= 1) {
            return $start > $globalEnd ? $globalEnd : $start;
        }
        $days = max(0, $chunkSizeDays - 1);
        $candidate = $start->add(new \DateInterval(\sprintf('P%dD', $days)));

        return $candidate > $globalEnd ? $globalEnd : $candidate;
    }

    private function normalizeDate(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return $value->setTime(0, 0, 0, 0);
    }

    private function formatDbTs(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function toDecimalString(mixed $v): ?string
    {
        if (null === $v || '' === $v) {
            return null;
        }
        if (\is_numeric($v)) {
            return \number_format((float) $v, 2, '.', '');
        }

        return (string) $v; // WB иногда отдаёт строки — сохраняем как есть
    }
}
