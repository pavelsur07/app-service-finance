<?php

namespace App\Service\Wildberries;

use App\Marketplace\Wildberries\Adapter\WildberriesReportsApiClient;
use App\Entity\Company;
use App\Marketplace\Wildberries\Entity\WildberriesSale;
use App\Repository\Wildberries\WildberriesSaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

readonly class WildberriesSalesImporter
{
    public function __construct(
        private WildberriesReportsApiClient $client,
        private EntityManagerInterface $entityManager,
        private WildberriesSaleRepository $salesRepository,
        private ?LoggerInterface $logger = null,
    ) {
        $this->logger ??= new NullLogger();
    }

    public function import(Company $company, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): int
    {
        $apiKey = $company->getWildberriesApiKey();
        if (!$apiKey) {
            $this->logger->warning('Wildberries API key is missing, skipping import', [
                'companyId' => $company->getId(),
            ]);

            return 0;
        }

        $rows = $this->client->fetchDetailedSales($company, $dateFrom, $dateTo);
        $processed = 0;

        $processedSales = [];
        $orderStatuses = $this->loadOrderStatuses($company, $dateFrom, $dateTo);

        foreach ($rows as $row) {
            $srid = (string) ($row['srid'] ?? '');
            if ('' === $srid) {
                continue;
            }

            if (isset($processedSales[$srid])) {
                $sale = $processedSales[$srid];
            } else {
                $sale = $this->salesRepository->findOneByCompanyAndSrid($company, $srid);
                if (!$sale) {
                    $sale = new WildberriesSale(Uuid::uuid4()->toString(), $company, $srid);
                }

                $processedSales[$srid] = $sale;
            }

            $soldAt = isset($row['date']) ? new \DateTimeImmutable($row['date']) : $dateFrom;
            $sale->setSoldAt($soldAt);
            $sale->setSupplierArticle($row['supplierArticle'] ?? null);
            $sale->setTechSize($row['techSize'] ?? null);
            $sale->setBarcode($row['barcode'] ?? null);
            $sale->setQuantity((int) ($row['quantity'] ?? 0));
            $sale->setPrice((string) ($row['price'] ?? '0'));
            $sale->setFinishedPrice((string) ($row['finishedPrice'] ?? $sale->getPrice()));
            $sale->setForPay(isset($row['forPay']) ? (string) $row['forPay'] : null);
            $sale->setDeliveryAmount(isset($row['deliveryAmount']) ? (string) $row['deliveryAmount'] : null);
            $sale->setOrderType(isset($row['orderType']) ? (string) $row['orderType'] : null);
            $saleStatus = null;
            foreach (['saleStatus', 'status', 'supplierStatus', 'supplierStatusName'] as $statusKey) {
                if (!array_key_exists($statusKey, $row) || null === $row[$statusKey]) {
                    continue;
                }

                $saleStatus = trim((string) $row[$statusKey]);
                if ('' !== $saleStatus) {
                    break;
                }

                $saleStatus = null;
            }
            if (null === $saleStatus && isset($orderStatuses[$srid])) {
                $saleStatus = $orderStatuses[$srid];
            }
            $sale->setSaleStatus($saleStatus);
            $sale->setWarehouseName(isset($row['warehouseName']) ? (string) $row['warehouseName'] : null);
            $sale->setOblast(isset($row['oblast']) ? (string) $row['oblast'] : null);
            $sale->setOdid(isset($row['odid']) ? (string) $row['odid'] : null);
            $sale->setSaleId(isset($row['saleID']) ? (string) $row['saleID'] : null);

            $statusUpdatedAt = null;
            if (!empty($row['lastChangeDate'])) {
                try {
                    $statusUpdatedAt = new \DateTimeImmutable($row['lastChangeDate']);
                } catch (\Exception $exception) {
                    $this->logger->warning('Failed to parse Wildberries lastChangeDate', [
                        'companyId' => $company->getId(),
                        'value' => $row['lastChangeDate'],
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }

            if ($statusUpdatedAt instanceof \DateTimeImmutable) {
                $sale->setStatusUpdatedAt($statusUpdatedAt);
            } elseif (null === $sale->getStatusUpdatedAt()) {
                $sale->setStatusUpdatedAt($soldAt);
            }
            $sale->setRaw($row);

            $this->entityManager->persist($sale);
            ++$processed;
        }

        if ($processed > 0) {
            $this->entityManager->flush();
        }

        return $processed;
    }

    /**
     * @return array<string, string>
     */
    private function loadOrderStatuses(Company $company, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        try {
            $orders = $this->client->fetchOrders($company, $dateFrom, $dateTo);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to load Wildberries order statuses', [
                'companyId' => $company->getId(),
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }

        if (isset($orders['orders']) && is_array($orders['orders'])) {
            $orders = $orders['orders'];
        }

        $statuses = [];
        foreach ($orders as $orderRow) {
            $srid = isset($orderRow['srid']) ? (string) $orderRow['srid'] : '';
            if ('' === $srid) {
                continue;
            }

            if (!array_key_exists('status', $orderRow)) {
                continue;
            }

            $status = null;
            foreach (['supplierStatusName', 'supplierStatus', 'status'] as $statusKey) {
                if (!array_key_exists($statusKey, $orderRow) || null === $orderRow[$statusKey]) {
                    continue;
                }

                $status = trim((string) $orderRow[$statusKey]);
                if ('' !== $status) {
                    break;
                }

                $status = null;
            }

            if (null === $status) {
                continue;
            }

            $statuses[$srid] = $status;
        }

        return $statuses;
    }
}
