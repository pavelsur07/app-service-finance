<?php

namespace App\Service\Wildberries;

use App\Api\Wildberries\WildberriesReportsApiClient;
use App\Entity\Company;
use App\Entity\Wildberries\WildberriesSale;
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

        foreach ($rows as $row) {
            $srid = (string) ($row['srid'] ?? '');
            if ('' === $srid) {
                continue;
            }

            $sale = $this->salesRepository->findOneByCompanyAndSrid($company, $srid);
            if (!$sale) {
                $sale = new WildberriesSale(Uuid::uuid4()->toString(), $company, $srid);
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
            $sale->setSaleStatus(isset($row['saleStatus']) ? (string) $row['saleStatus'] : null);
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
}
