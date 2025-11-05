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
        $this->logger->info(sprintf(
            '[WB:ReportDetail] Start import: company=%s, from=%s, to=%s, period=%s',
            $company->getId(),
            $dateFrom->format(\DATE_ATOM),
            $dateTo->format(\DATE_ATOM),
            $period
        ));

        $processed = 0;
        $rrdIdCursor = 0;

        try {
            while (true) {
                $payload = $this->client->fetchReportDetailByPeriod(
                    $company,
                    $dateFrom,
                    $dateTo,
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
                        $this->importLogger->incError();
                        continue;
                    }

                    $rrdId = (int) $row['rrd_id'];
                    if ($rrdId > $maxInBatch) {
                        $maxInBatch = $rrdId;
                    }

                    $entity = $this->repository->findOneByCompanyAndRrdId($company, $rrdId);
                    if (!$entity) {
                        $entity = new WildberriesReportDetail();
                        $entity->setId(Uuid::uuid4()->toString());
                        $entity->setCompany($company);
                        $entity->setRrdId($rrdId);
                        $entity->setCreatedAt(new \DateTimeImmutable());
                    }

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
                }

                $this->em->flush();
                $this->em->clear(WildberriesReportDetail::class);

                $processed += \count($payload);
                $rrdIdCursor = $maxInBatch;

                $this->logger->info(sprintf(
                    '[WB:ReportDetail] Batch imported: company=%s, batch=%d, processed_total=%d, next_rrd_cursor=%d',
                    $company->getId(),
                    \count($payload),
                    $processed,
                    $rrdIdCursor
                ));
            }

            $this->importLogger->success(
                'wildberries_report_detail',
                $company,
                $processed,
                $dateFrom,
                $dateTo
            );

            $this->logger->info(sprintf(
                '[WB:ReportDetail] Import finished: company=%s, processed=%d, window=[%s .. %s]',
                $company->getId(),
                $processed,
                $dateFrom->format(\DATE_ATOM),
                $dateTo->format(\DATE_ATOM)
            ));

            return $processed;
        } catch (\Throwable $e) {
            $this->importLogger->incError();
            $this->logger->error('[WB:ReportDetail] Import failed', [
                'company' => $company->getId(),
                'from' => $dateFrom->format(\DATE_ATOM),
                'to' => $dateTo->format(\DATE_ATOM),
                'period' => $period,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
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
