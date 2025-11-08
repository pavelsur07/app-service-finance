<?php

namespace App\Marketplace\Wildberries\Service;

use App\Entity\Company;
use App\Marketplace\Wildberries\Adapter\WildberriesStatisticsV5Client;
use App\Marketplace\Wildberries\Entity\WildberriesReportDetail;
use App\Marketplace\Wildberries\Repository\WildberriesReportDetailRepository;
use App\Service\Import\ImportLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class WildberriesReportDetailImporter
{
    public function __construct(
        private readonly WildberriesStatisticsV5Client $client,
        private readonly WildberriesReportDetailRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ImportLogger $importLogger,
    ) {
    }

    /**
     * Импорт детализации за интервал дат (WB v5, period=daily|weekly).
     *
     * @return int Количество обработанных строк
     */
    public function import(Company $company, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo, string $period = 'daily'): int
    {
        $dateFrom = $this->normalizeDate($dateFrom);
        $dateTo = $this->normalizeDate($dateTo);

        $companyId = $company->getId();
        if (null === $companyId) {
            throw new \RuntimeException('Cannot import WB report details for a company without identifier');
        }

        $companyId = (string) $companyId;

        $company = $this->em->getReference(Company::class, $companyId);

        $this->logger->info(sprintf(
            '[WB:ReportDetail] Start import: company=%s, from=%s, to=%s, period=%s',
            $companyId,
            $dateFrom->format(\DATE_ATOM),
            $dateTo->format(\DATE_ATOM),
            $period
        ));

        $processed = 0;
        $log = $this->importLogger->start(
            $company,
            'wildberries_report_detail',
            false,
            null,
            null
        );

        try {
            foreach ($this->iterateDateWindows($dateFrom, $dateTo, $period) as [$windowFrom, $windowTo]) {
                $rrdIdCursor = 0;

                while (true) {
                    $payload = $this->client->fetchReportDetailByPeriod(
                        $company,
                        $windowFrom,
                        $windowTo,
                        $rrdIdCursor,
                        $period
                    );

                    if (empty($payload)) {
                        break;
                    }

                    $maxInBatch = $rrdIdCursor;

                    foreach ($payload as $row) {
                        if (!isset($row['rrd_id'])) {
                            $this->logger->warning('[WB:ReportDetail] Skip row without rrd_id', ['row' => $row]);
                            $this->importLogger->incError($log);
                            continue;
                        }

                        $rrdId = (int) $row['rrd_id'];
                        if ($rrdId > $maxInBatch) {
                            $maxInBatch = $rrdId;
                        }

                        $entity = $this->repository->findOneByCompanyAndRrdId($company, $rrdId);
                        $isNewEntity = null === $entity;
                        if (!$entity) {
                            $entity = new WildberriesReportDetail();
                            $entity->setId(Uuid::uuid4()->toString());
                            $entity->setCompany($company);
                            $entity->setRrdId($rrdId);
                            $entity->setCreatedAt(new \DateTimeImmutable());
                        }

                        $entity->setImportId($log->getId());

                        // WB identifiers
                        $entity->setRealizationreportId(isset($row['realizationreport_id']) ? (int) $row['realizationreport_id'] : null);

                        // Dates
                        $entity->setSaleDt($this->parseDt($row['sale_dt'] ?? null));
                        $entity->setRrDt($this->parseDt($row['rr_dt'] ?? null));
                        $entity->setOrderDt($this->parseDt($row['order_dt'] ?? null));

                        // Nomenclature
                        $entity->setNmId(isset($row['nm_id']) ? (int) $row['nm_id'] : null);
                        $entity->setBarcode($row['barcode'] ?? null);
                        $entity->setSubjectName($row['subject_name'] ?? null);
                        $entity->setBrandName($row['brand_name'] ?? null);

                        // Amounts (decimal(15,2) — строки)
                        $entity->setRetailPrice($this->toDecimalString($row['retail_price'] ?? null));
                        $entity->setRetailPriceWithDiscRub($this->toDecimalString($row['retail_price_with_disc_rub'] ?? null));
                        $entity->setPpvzSalesCommission($this->toDecimalString($row['ppvz_sales_commission'] ?? null));
                        $entity->setPpvzForPay($this->toDecimalString($row['ppvz_for_pay'] ?? null));
                        $entity->setDeliveryRub($this->toDecimalString($row['delivery_rub'] ?? null));
                        $entity->setStorageFee($this->toDecimalString($row['storage_fee'] ?? null));
                        $entity->setAcceptance($this->toDecimalString($row['acceptance'] ?? null));
                        $entity->setPenalty($this->toDecimalString($row['penalty'] ?? null));
                        $entity->setAcquiringFee($this->toDecimalString($row['acquiring_fee'] ?? null));

                        // Other fields
                        $entity->setSiteCountry($row['site_country'] ?? null);
                        $entity->setSupplierOperName($row['supplier_oper_name'] ?? null);
                        $entity->setDocTypeName($row['doc_type_name'] ?? null);

                        // Status / Updated
                        $entity->setStatusUpdatedAt($entity->getRrDt() ?: new \DateTimeImmutable());
                        $entity->setRaw(is_array($row) ? $row : []);
                        $entity->setUpdatedAt(new \DateTimeImmutable());

                        $this->em->persist($entity);

                        if ($isNewEntity) {
                            $this->importLogger->incCreated($log);
                        }
                    }

                    $this->em->flush();
                    $this->em->clear(WildberriesReportDetail::class);

                    $processed += \count($payload);
                    $nextCursor = $maxInBatch;

                    $this->logger->info(sprintf(
                        '[WB:ReportDetail] Batch imported: company=%s, window=[%s .. %s], batch=%d, processed_total=%d, next_rrd_cursor=%d',
                        $companyId,
                        $windowFrom->format(\DATE_ATOM),
                        $windowTo->format(\DATE_ATOM),
                        \count($payload),
                        $processed,
                        $nextCursor
                    ));

                    if ($nextCursor === $rrdIdCursor) {
                        $this->logger->warning('[WB:ReportDetail] Cursor did not advance, stop pagination to avoid infinite loop', [
                            'company' => $companyId,
                            'window_from' => $windowFrom->format(\DATE_ATOM),
                            'window_to' => $windowTo->format(\DATE_ATOM),
                            'cursor' => $rrdIdCursor,
                        ]);
                        break;
                    }

                    $rrdIdCursor = $nextCursor;
                }
            }

            $this->logger->info(sprintf(
                '[WB:ReportDetail] Import finished: company=%s, processed=%d, window=[%s .. %s]',
                $companyId,
                $processed,
                $dateFrom->format(\DATE_ATOM),
                $dateTo->format(\DATE_ATOM)
            ));

            return $processed;
        } catch (\Throwable $e) {
            $this->importLogger->incError($log);
            $this->logger->error('[WB:ReportDetail] Import failed', [
                'company' => $companyId,
                'from' => $dateFrom->format(\DATE_ATOM),
                'to' => $dateTo->format(\DATE_ATOM),
                'period' => $period,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $this->importLogger->finish($log);
        }
    }

    /**
     * Делит исходный интервал на более мелкие окна, чтобы ограничить объём ответа WB и снизить потребление памяти.
     *
     * Возвращает массивы вида [\DateTimeImmutable $from, \DateTimeImmutable $to] без перекрытия.
     */
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
        $candidate = $start->add(new \DateInterval(sprintf('P%dD', $days)));

        return $candidate > $globalEnd ? $globalEnd : $candidate;
    }

    private function normalizeDate(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return $value->setTime(0, 0, 0, 0);
    }

    private function parseDt(?string $value): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function toDecimalString(mixed $v): ?string
    {
        if (null === $v || '' === $v) {
            return null;
        }
        if (is_numeric($v)) {
            return number_format((float) $v, 2, '.', '');
        }

        return (string) $v;
    }
}
