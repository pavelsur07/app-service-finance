<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Inventory\Application;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceListingBarcode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Inventory\Application\Command\ImportInventoryCostPriceFromFileCommand;
use App\Marketplace\Inventory\Application\ImportInventoryCostPriceFromFileAction;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Ramsey\Uuid\Uuid;

final class ImportInventoryCostPriceFromFileActionTest extends IntegrationTestCase
{
    private const COMPANY_A_ID = '11111111-1111-1111-1111-0000000000c1';
    private const COMPANY_B_ID = '11111111-1111-1111-1111-0000000000c2';

    private ImportInventoryCostPriceFromFileAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = self::getContainer()->get(ImportInventoryCostPriceFromFileAction::class);
    }

    public function testWbSupplierSkuUpdatesEverySizeWithoutCrossingTenantOrMarketplace(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, 901);
        $companyB = $this->seedCompany(self::COMPANY_B_ID, 902);

        $sizeS = $this->seedListing($companyA, MarketplaceType::WILDBERRIES, '10001', '101', 'S', 'ART-001');
        $sizeM = $this->seedListing($companyA, MarketplaceType::WILDBERRIES, '10001', '102', 'M', 'ART-001');
        $otherArticle = $this->seedListing($companyA, MarketplaceType::WILDBERRIES, '10002', '103', 'L', 'ART-002');
        $ozon = $this->seedListing($companyA, MarketplaceType::OZON, '20001', null, 'UNKNOWN', 'ART-001');
        $otherCompany = $this->seedListing($companyB, MarketplaceType::WILDBERRIES, '30001', '301', 'S', 'ART-001');
        $this->em->flush();

        $file = $this->xlsx([
            ['Артикул продавца', 'Себестоимость'],
            ['ART-001', '850.00'],
        ]);

        try {
            $result = ($this->action)(new ImportInventoryCostPriceFromFileCommand(
                companyId: self::COMPANY_A_ID,
                absoluteFilePath: $file,
                originalFilename: 'wb-costs.xlsx',
                effectiveFrom: new \DateTimeImmutable('2026-07-29'),
                marketplace: MarketplaceType::WILDBERRIES,
                identifierType: 'supplier_sku',
            ));
        } finally {
            unlink($file);
        }

        self::assertSame(1, $result['imported']);
        self::assertSame(2, $result['updated_listings']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([], $result['errors']);

        $prices = $this->pricesByListing(self::COMPANY_A_ID);
        self::assertSame('850.00', $prices[$sizeS->getId()] ?? null);
        self::assertSame('850.00', $prices[$sizeM->getId()] ?? null);
        self::assertArrayNotHasKey($otherArticle->getId(), $prices);
        self::assertArrayNotHasKey($ozon->getId(), $prices);

        $otherCompanyPrices = $this->pricesByListing(self::COMPANY_B_ID);
        self::assertArrayNotHasKey($otherCompany->getId(), $otherCompanyPrices);
    }

    public function testWbSupplierSkuMatchingIgnoresCyrillicCase(): void
    {
        $company = $this->seedCompany(self::COMPANY_A_ID, 906);
        $sizeS = $this->seedListing($company, MarketplaceType::WILDBERRIES, '10001', '101', 'S', 'рт-00000056');
        $sizeM = $this->seedListing($company, MarketplaceType::WILDBERRIES, '10001', '102', 'M', 'рт-00000056');
        $this->em->flush();

        $file = $this->xlsx([['РТ-00000056', '850.00']]);

        try {
            $result = ($this->action)(new ImportInventoryCostPriceFromFileCommand(
                companyId: self::COMPANY_A_ID,
                absoluteFilePath: $file,
                originalFilename: 'wb-costs.xlsx',
                effectiveFrom: new \DateTimeImmutable('2026-07-29'),
                marketplace: MarketplaceType::WILDBERRIES,
                identifierType: 'supplier_sku',
            ));
        } finally {
            unlink($file);
        }

        self::assertSame(1, $result['imported']);
        self::assertSame(2, $result['updated_listings']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([], $result['errors']);

        $prices = $this->pricesByListing(self::COMPANY_A_ID);
        self::assertSame('850.00', $prices[$sizeS->getId()] ?? null);
        self::assertSame('850.00', $prices[$sizeM->getId()] ?? null);
    }

    public function testWbSupplierSkuMatchingFoldsStoredUppercaseCyrillic(): void
    {
        $company = $this->seedCompany(self::COMPANY_A_ID, 909);
        $sizeS = $this->seedListing($company, MarketplaceType::WILDBERRIES, '10001', '101', 'S', 'РТ-00000056');
        $sizeM = $this->seedListing($company, MarketplaceType::WILDBERRIES, '10001', '102', 'M', 'РТ-00000056');
        $this->em->flush();

        $file = $this->xlsx([['рт-00000056', '850.00']]);

        try {
            $result = ($this->action)(new ImportInventoryCostPriceFromFileCommand(
                companyId: self::COMPANY_A_ID,
                absoluteFilePath: $file,
                originalFilename: 'wb-costs.xlsx',
                effectiveFrom: new \DateTimeImmutable('2026-07-29'),
                marketplace: MarketplaceType::WILDBERRIES,
                identifierType: 'supplier_sku',
            ));
        } finally {
            unlink($file);
        }

        self::assertSame(1, $result['imported']);
        self::assertSame(2, $result['updated_listings']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([], $result['errors']);

        $prices = $this->pricesByListing(self::COMPANY_A_ID);
        self::assertSame('850.00', $prices[$sizeS->getId()] ?? null);
        self::assertSame('850.00', $prices[$sizeM->getId()] ?? null);
    }

    public function testOzonSupplierSkuStillRejectsAmbiguousMatches(): void
    {
        $company = $this->seedCompany(self::COMPANY_A_ID, 903);
        $this->seedListing($company, MarketplaceType::OZON, '20001', null, 'UNKNOWN', 'DUPLICATE');
        $this->seedListing($company, MarketplaceType::OZON, '20002', null, 'UNKNOWN', 'DUPLICATE');
        $this->em->flush();

        $file = $this->xlsx([['DUPLICATE', '100.00']]);

        try {
            $result = ($this->action)(new ImportInventoryCostPriceFromFileCommand(
                companyId: self::COMPANY_A_ID,
                absoluteFilePath: $file,
                originalFilename: 'ozon-costs.xlsx',
                effectiveFrom: new \DateTimeImmutable('2026-07-29'),
                marketplace: MarketplaceType::OZON,
                identifierType: 'supplier_sku',
            ));
        } finally {
            unlink($file);
        }

        self::assertSame(0, $result['imported']);
        self::assertSame(0, $result['updated_listings']);
        self::assertSame(1, $result['skipped']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('неоднозначный supplier_sku "DUPLICATE"', $result['errors'][0]);
        self::assertSame([], $this->pricesByListing(self::COMPANY_A_ID));
    }

    public function testOzonSupplierSkuMatchingRemainsCaseSensitive(): void
    {
        $company = $this->seedCompany(self::COMPANY_A_ID, 907);
        $this->seedListing($company, MarketplaceType::OZON, '20001', null, 'UNKNOWN', 'article-001');
        $this->em->flush();

        $file = $this->xlsx([['ARTICLE-001', '100.00']]);

        try {
            $result = ($this->action)(new ImportInventoryCostPriceFromFileCommand(
                companyId: self::COMPANY_A_ID,
                absoluteFilePath: $file,
                originalFilename: 'ozon-costs.xlsx',
                effectiveFrom: new \DateTimeImmutable('2026-07-29'),
                marketplace: MarketplaceType::OZON,
                identifierType: 'supplier_sku',
            ));
        } finally {
            unlink($file);
        }

        self::assertSame(0, $result['imported']);
        self::assertSame(0, $result['updated_listings']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(
            ['Строка 1: идентификатор ARTICLE-001 не найден'],
            $result['errors'],
        );
    }

    public function testWbSupplierSkuRejectsCaseInsensitiveAmbiguity(): void
    {
        $company = $this->seedCompany(self::COMPANY_A_ID, 908);
        $this->seedListing($company, MarketplaceType::WILDBERRIES, '10001', '101', 'S', 'ART-001');
        $this->seedListing($company, MarketplaceType::WILDBERRIES, '10002', '102', 'M', 'art-001');
        $this->em->flush();

        $file = $this->xlsx([['Art-001', '100.00']]);

        try {
            $result = ($this->action)(new ImportInventoryCostPriceFromFileCommand(
                companyId: self::COMPANY_A_ID,
                absoluteFilePath: $file,
                originalFilename: 'wb-costs.xlsx',
                effectiveFrom: new \DateTimeImmutable('2026-07-29'),
                marketplace: MarketplaceType::WILDBERRIES,
                identifierType: 'supplier_sku',
            ));
        } finally {
            unlink($file);
        }

        self::assertSame(0, $result['imported']);
        self::assertSame(0, $result['updated_listings']);
        self::assertSame(1, $result['skipped']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString(
            'неоднозначный артикул продавца "Art-001": найдено несколько вариантов регистра: ART-001, art-001',
            $result['errors'][0],
        );
        self::assertSame([], $this->pricesByListing(self::COMPANY_A_ID));
    }

    public function testBarcodeImportStillUpdatesOnlyResolvedListing(): void
    {
        $company = $this->seedCompany(self::COMPANY_A_ID, 904);
        $target = $this->seedListing($company, MarketplaceType::WILDBERRIES, '10001', '101', 'S', 'ART-001');
        $other = $this->seedListing($company, MarketplaceType::WILDBERRIES, '10002', '102', 'M', 'ART-001');
        $barcode = new MarketplaceListingBarcode(
            Uuid::uuid4()->toString(),
            $target,
            self::COMPANY_A_ID,
            MarketplaceType::WILDBERRIES->value,
            '460000000001',
        );
        $this->em->persist($barcode);
        $this->em->flush();

        $file = $this->xlsx([['460000000001', '125.00']]);

        try {
            $result = ($this->action)(new ImportInventoryCostPriceFromFileCommand(
                companyId: self::COMPANY_A_ID,
                absoluteFilePath: $file,
                originalFilename: 'wb-barcode-costs.xlsx',
                effectiveFrom: new \DateTimeImmutable('2026-07-29'),
                marketplace: MarketplaceType::WILDBERRIES,
                identifierType: 'barcode',
            ));
        } finally {
            unlink($file);
        }

        self::assertSame(1, $result['imported']);
        self::assertSame(1, $result['updated_listings']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([], $result['errors']);

        $prices = $this->pricesByListing(self::COMPANY_A_ID);
        self::assertSame('125.00', $prices[$target->getId()] ?? null);
        self::assertArrayNotHasKey($other->getId(), $prices);
    }

    public function testWbSupplierSkuSkipsUnknownArticle(): void
    {
        $this->seedCompany(self::COMPANY_A_ID, 905);
        $this->em->flush();

        $file = $this->xlsx([['UNKNOWN-ARTICLE', '100.00']]);

        try {
            $result = ($this->action)(new ImportInventoryCostPriceFromFileCommand(
                companyId: self::COMPANY_A_ID,
                absoluteFilePath: $file,
                originalFilename: 'wb-unknown-costs.xlsx',
                effectiveFrom: new \DateTimeImmutable('2026-07-29'),
                marketplace: MarketplaceType::WILDBERRIES,
                identifierType: 'supplier_sku',
            ));
        } finally {
            unlink($file);
        }

        self::assertSame(0, $result['imported']);
        self::assertSame(0, $result['updated_listings']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(
            ['Строка 1: идентификатор UNKNOWN-ARTICLE не найден'],
            $result['errors'],
        );
    }

    private function seedCompany(string $companyId, int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function seedListing(
        Company $company,
        MarketplaceType $marketplace,
        string $marketplaceSku,
        ?string $variantId,
        string $size,
        string $supplierSku,
    ): MarketplaceListing {
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace($marketplace)
            ->withMarketplaceSku($marketplaceSku)
            ->withMarketplaceVariantId($variantId)
            ->build();
        $listing->setSize($size);
        $listing->setSupplierSku($supplierSku);
        $this->em->persist($listing);

        return $listing;
    }

    /**
     * @param list<list<string>> $rows
     */
    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'inventory-cost-import-');
        if (false === $path) {
            throw new \RuntimeException('Failed to create temporary XLSX file.');
        }

        $writer = new Writer();
        $writer->openToFile($path);
        try {
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function pricesByListing(string $companyId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT listing_id, price_amount
             FROM marketplace_inventory_cost_prices
             WHERE company_id = :companyId',
            ['companyId' => $companyId],
        );

        $prices = [];
        foreach ($rows as $row) {
            $prices[(string) $row['listing_id']] = (string) $row['price_amount'];
        }

        return $prices;
    }
}
