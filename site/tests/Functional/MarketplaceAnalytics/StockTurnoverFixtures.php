<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceAnalytics;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Inventory\Enum\StockStatus;
use App\Marketplace\Entity\Inventory\MarketplaceInventoryCostPrice;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Сид отчёта «Оборачиваемость» для functional-тестов страницы и экспорта.
 * Стандартный набор: A 412/39/1800 → свыше 90; B 48/186/600 → до 30; C 20/0/100 → нет продаж; D 15/3 без цены.
 */
trait StockTurnoverFixtures
{
    private \DateTimeImmutable $date;
    private int $saleIndex = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = new \DateTimeImmutable('yesterday');
    }

    /**
     * @return array{User, Company}
     */
    private function seedCompany(string $email, string $companyId): array
    {
        $owner = UserBuilder::aUser()->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);

        return [$owner, $company];
    }

    /** A 412/39/1800 → свыше 90; B 48/186/600 → до 30; C 20/0/100 → нет продаж; D 15/3 без цены. */
    private function seedStandardSet(Company $company): void
    {
        $tail = substr((string) $company->getId(), -2);
        $base = 9000 + (int) hexdec($tail) * 10;
        $a = $this->listing($company, $base + 1, 'SKU-A', 'Худи серое');
        $b = $this->listing($company, $base + 2, 'SKU-B', 'Футболка чёрная');
        $c = $this->listing($company, $base + 3, 'SKU-C', 'Носки');
        $d = $this->listing($company, $base + 4, 'SKU-D', 'Кепка');

        $this->snapshot($company, $a, '412.000');
        $this->snapshot($company, $b, '48.000');
        $this->snapshot($company, $c, '20.000');
        $this->snapshot($company, $d, '15.000');
        $this->costPrice($company, $a, '1800.00');
        $this->costPrice($company, $b, '600.00');
        $this->costPrice($company, $c, '100.00');
        $this->sale($company, $a, 39);
        $this->sale($company, $b, 186);
        $this->sale($company, $d, 3);
        $this->em()->flush();
    }

    private function listing(Company $company, int $index, string $sku, string $name): MarketplaceListing
    {
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withId(Uuid::uuid4()->toString())
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku($sku)
            ->withName($name)
            ->build();
        $this->em()->persist($listing);

        return $listing;
    }

    private function snapshot(Company $company, MarketplaceListing $listing, string $qty, ?\DateTimeImmutable $date = null): void
    {
        $date ??= $this->date;
        $this->em()->persist(
            StockSnapshotBuilder::aStockSnapshot()
                ->withCompanyId((string) $company->getId())
                ->withSnapshotSessionId(Uuid::uuid5(Uuid::NAMESPACE_DNS, (string) $company->getId())->toString())
                ->withSnapshotDate($date)
                ->withSnapshotAt($date->setTime(4, 5))
                ->withSource(MarketplaceType::OZON)
                ->withListingId($listing->getId())
                ->withProductId(null)
                ->withStatus(StockStatus::Available)
                ->withFulfillmentType('fbo')
                ->withSourceSku($listing->getMarketplaceSku())
                ->withQuantity($qty)
                ->build(),
        );
    }

    private function costPrice(Company $company, MarketplaceListing $listing, string $amount): void
    {
        $this->em()->persist(new MarketplaceInventoryCostPrice(
            Uuid::uuid4()->toString(),
            (string) $company->getId(),
            $listing,
            $this->date->modify('-100 days'),
            $amount,
        ));
    }

    private function sale(Company $company, MarketplaceListing $listing, int $quantity): void
    {
        $this->em()->persist(
            MarketplaceSaleBuilder::aSale()
                ->forCompany($company)
                ->forListing($listing)
                ->withId(Uuid::uuid4()->toString())
                ->withExternalOrderId('TURN-'.++$this->saleIndex.'-'.substr((string) $company->getId(), -4))
                ->withSaleDate($this->date->modify('-3 days'))
                ->withQuantity($quantity)
                ->build(),
        );
    }

    private function login(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
    }
}
