<?php

namespace App\Service\Ozon;

use App\Api\Ozon\OzonApiClient;
use App\Entity\Company;
use App\Marketplace\Ozon\Entity\OzonProduct;
use App\Marketplace\Ozon\Entity\OzonProductSales;
use App\Repository\Ozon\OzonProductRepository;
use App\Repository\Ozon\OzonProductSalesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

readonly class OzonProductSalesService
{
    public function __construct(
        private OzonApiClient $client,
        private EntityManagerInterface $em,
        private OzonProductRepository $productRepository,
        private OzonProductSalesRepository $salesRepository,
    ) {
    }

    public function createSalesReport(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return $this->client->createSalesReport(
            $company->getOzonSellerId(),
            $company->getOzonApiKey(),
            $from,
            $to
        );
    }

    public function downloadAndParseReport(Company $company, string $taskId): array
    {
        $csv = $this->client->downloadSalesReport(
            $company->getOzonSellerId(),
            $company->getOzonApiKey(),
            $taskId
        );

        if (!$csv) {
            return [];
        }

        $lines = preg_split('/\r?\n/', trim($csv));
        $rows = [];
        foreach ($lines as $line) {
            if ('' === $line) {
                continue;
            }
            $rows[] = str_getcsv($line, ';');
        }

        return $rows;
    }

    public function saveSales(Company $company, array $rows, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        foreach ($rows as $row) {
            $offerId = $row[0] ?? null;
            $qty = isset($row[1]) ? (int) $row[1] : 0;

            if (!$offerId) {
                continue;
            }

            $product = $this->productRepository->findOneBy([
                'manufacturerSku' => $offerId,
                'company' => $company,
            ]);

            if (!$product instanceof OzonProduct) {
                continue;
            }

            $sale = $this->salesRepository->findOneByPeriod($product, $company, $from, $to);
            if (!$sale) {
                $sale = new OzonProductSales(Uuid::uuid4()->toString(), $product, $company, $from, $to);
                $this->em->persist($sale);
            }

            $sale->setQty($qty);
        }

        $this->em->flush();
    }
}
