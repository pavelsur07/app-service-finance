<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceListingBarcode;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceListingBarcodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceListingBarcode::class);
    }

    /**
     * Найти barcode-запись по barcode + companyId + marketplace.
     * JOIN через listing.marketplace исключает коллизии между маркетплейсами.
     */
    public function findByBarcode(
        string $companyId,
        string $barcode,
        MarketplaceType $marketplace,
    ): ?MarketplaceListingBarcode {
        return $this->createQueryBuilder('b')
            ->join('b.listing', 'l')
            ->where('b.companyId = :companyId')
            ->andWhere('b.barcode = :barcode')
            ->andWhere('l.marketplace = :marketplace')
            ->setParameter('companyId', $companyId)
            ->setParameter('barcode', $barcode)
            ->setParameter('marketplace', $marketplace)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Массовая загрузка по списку barcodes — индексировано по barcode.
     *
     * @param string[] $barcodes
     * @return array<string, MarketplaceListingBarcode>
     */
    public function findByBarcodesIndexed(
        string $companyId,
        array $barcodes,
        MarketplaceType $marketplace,
    ): array {
        if (empty($barcodes)) {
            return [];
        }

        $results = $this->createQueryBuilder('b')
            ->join('b.listing', 'l')
            ->addSelect('l')
            ->where('b.companyId = :companyId')
            ->andWhere('b.barcode IN (:barcodes)')
            ->andWhere('l.marketplace = :marketplace')
            ->setParameter('companyId', $companyId)
            ->setParameter('barcodes', $barcodes)
            ->setParameter('marketplace', $marketplace)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($results as $barcodeEntity) {
            $indexed[$barcodeEntity->getBarcode()] = $barcodeEntity;
        }

        return $indexed;
    }

    /**
     * Проверить существование barcode для компании (без учёта marketplace —
     * barcode глобально уникален в рамках компании).
     */
    public function existsForCompany(string $companyId, string $barcode): bool
    {
        return $this->findOneBy(['companyId' => $companyId, 'barcode' => $barcode]) !== null;
    }
}
