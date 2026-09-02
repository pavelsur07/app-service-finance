<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Wildberries;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Ingestion\Application\Service\ListingResolverRegistry;
use App\Ingestion\Enum\IngestSource;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class WbListingResolverTest extends IntegrationTestCase
{
    public function testResolvesByNmIdAndFallsBackToSupplierArticle(): void
    {
        $company = $this->createCompany();
        $byNmId = $this->newListing($company, 'ART-BY-NM', '20000001');
        $byArticle = $this->newListing($company, 'ART-ONLY', '20000002');
        $this->em->flush();
        $this->em->clear();

        $registry = $this->registry();
        $companyId = (string) $company->getId();

        $resolutions = $registry->resolveMany(IngestSource::WILDBERRIES, $companyId, [
            'по nmId' => ['nm_id' => '20000001', 'supplier_article' => 'ART-BY-NM'],
            'по артикулу' => ['nm_id' => '99999999', 'supplier_article' => 'ART-ONLY'],
            'ничего не найдено' => ['nm_id' => '77777777', 'supplier_article' => 'ART-MISSING'],
        ]);

        self::assertSame($byNmId->getId(), $resolutions['по nmId']?->listingId);
        self::assertSame($byArticle->getId(), $resolutions['по артикулу']?->listingId);

        // Нерезолвленное не теряется: позиция сохранит свой ключ поиска на
        // уровне нормализации, а сам резолвер честно отвечает «не нашёл».
        self::assertNull($resolutions['ничего не найдено']);
    }

    /**
     * nmId старше артикула: его присваивает WB и он уникален, а артикул задаёт
     * продавец и может повторяться между карточками.
     */
    public function testNmIdWinsOverSupplierArticle(): void
    {
        $company = $this->createCompany();
        $viaNmId = $this->newListing($company, 'SHARED-ART', '20000001');
        $this->newListing($company, 'SHARED-ART', '20000002');
        $this->em->flush();
        $this->em->clear();

        $resolution = $this->registry()->resolve(IngestSource::WILDBERRIES, (string) $company->getId(), [
            'nm_id' => '20000001',
            'supplier_article' => 'SHARED-ART',
        ]);

        self::assertSame($viaNmId->getId(), $resolution?->listingId);
    }

    /**
     * Листинг чужой компании не должен находиться: резолвер ходит через фасад,
     * но companyId обязан ограничивать выборку.
     */
    public function testListingsOfAnotherCompanyAreNotResolved(): void
    {
        $owner = $this->createCompany();
        $stranger = $this->createCompany('wb-listing-stranger@example.com', 'WB Stranger');
        $this->newListing($stranger, 'ART-STRANGER', '20000003');
        $this->em->flush();
        $this->em->clear();

        $resolution = $this->registry()->resolve(IngestSource::WILDBERRIES, (string) $owner->getId(), [
            'nm_id' => '20000003',
            'supplier_article' => 'ART-STRANGER',
        ]);

        self::assertNull($resolution);
    }

    /**
     * Листинг Ozon с тем же номером не должен подхватываться как WB.
     */
    public function testListingOfAnotherMarketplaceIsNotResolved(): void
    {
        $company = $this->createCompany();
        $listing = new MarketplaceListing(
            id: Uuid::uuid7()->toString(),
            company: $company,
            product: null,
            marketplace: MarketplaceType::OZON,
        );
        $listing->setSupplierSku('ART-OZON');
        $listing->setMarketplaceSku('20000004');
        $listing->setPrice('1000.00');
        $this->em->persist($listing);
        $this->em->flush();
        $this->em->clear();

        $resolution = $this->registry()->resolve(IngestSource::WILDBERRIES, (string) $company->getId(), [
            'nm_id' => '20000004',
            'supplier_article' => 'ART-OZON',
        ]);

        self::assertNull($resolution);
    }

    public function testEmptyInputResolvesToEmptyResult(): void
    {
        $company = $this->createCompany();
        $this->em->flush();

        self::assertSame([], $this->registry()->resolveMany(IngestSource::WILDBERRIES, (string) $company->getId(), []));
    }

    /**
     * Позиция без обоих ключей поиска не должна ронять разбор батча.
     */
    public function testRowWithoutAnyKeyResolvesToNull(): void
    {
        $company = $this->createCompany();
        $this->em->flush();

        $resolutions = $this->registry()->resolveMany(IngestSource::WILDBERRIES, (string) $company->getId(), [
            0 => ['barcode' => '4600000000001'],
        ]);

        self::assertNull($resolutions[0]);
    }

    private function registry(): ListingResolverRegistry
    {
        /** @var ListingResolverRegistry $registry */
        $registry = self::getContainer()->get(ListingResolverRegistry::class);

        return $registry;
    }

    private function createCompany(string $email = 'wb-listing-resolver@example.com', string $name = 'WB Listing Resolver Company'): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setPassword('password');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName($name);

        $this->em->persist($user);
        $this->em->persist($company);

        return $company;
    }

    private function newListing(Company $company, string $supplierSku, string $marketplaceSku): MarketplaceListing
    {
        $listing = new MarketplaceListing(
            id: Uuid::uuid7()->toString(),
            company: $company,
            product: null,
            marketplace: MarketplaceType::WILDBERRIES,
        );
        $listing->setSupplierSku($supplierSku);
        $listing->setMarketplaceSku($marketplaceSku);
        $listing->setPrice('1000.00');

        $this->em->persist($listing);

        return $listing;
    }
}
