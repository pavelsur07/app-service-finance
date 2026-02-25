<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductPurchasePrice;
use App\Catalog\Facade\ProductPurchasePriceFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class ProductPurchasePriceFacadeTest extends IntegrationTestCase
{
    public function testGetPurchasePriceAtReturnsDtoWithExpectedFields(): void
    {
        $owner = UserBuilder::aUser()->withEmail('owner-purchase-price-facade@example.test')->build();
        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111194')
            ->withOwner($owner)
            ->withName('Purchase Price Facade Company')
            ->build();

        $product = (new Product('33333333-3333-3333-3333-333333333394', $company))
            ->setName('Facade Product')
            ->setSku('SKU-FACADE-001')
            ->setPurchasePrice('500.00');

        $purchasePrice = new ProductPurchasePrice(
            id: '44444444-4444-4444-4444-444444444494',
            company: $company,
            product: $product,
            effectiveFrom: new \DateTimeImmutable('2026-03-01'),
            priceAmount: 145000,
            priceCurrency: 'RUB',
            note: 'Контрактная цена поставщика',
        );

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($product);
        $this->em->persist($purchasePrice);
        $this->em->flush();

        $facade = self::getContainer()->get(ProductPurchasePriceFacade::class);
        $dto = $facade->getPurchasePriceAt(
            '11111111-1111-1111-1111-111111111194',
            '33333333-3333-3333-3333-333333333394',
            new \DateTimeImmutable('2026-03-10'),
        );

        self::assertNotNull($dto);
        self::assertSame('2026-03-01', $dto->effectiveFrom);
        self::assertNull($dto->effectiveTo);
        self::assertSame(145000, $dto->amount);
        self::assertSame('RUB', $dto->currency);
        self::assertSame('Контрактная цена поставщика', $dto->note);
    }
}
