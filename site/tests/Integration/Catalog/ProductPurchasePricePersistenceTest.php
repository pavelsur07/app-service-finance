<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductPurchasePrice;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class ProductPurchasePricePersistenceTest extends IntegrationTestCase
{
    public function testItPersistsAndLoadsPurchasePriceHistory(): void
    {
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('owner-product-purchase-price@example.test')->build();
        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111190')
            ->withOwner($owner)
            ->withName('Purchase Price Company')
            ->build();

        $product = (new Product('33333333-3333-3333-3333-333333333390', $company))
            ->setName('Purchase Price Product')
            ->setSku('SKU-PRICE-001')
            ->setPurchasePrice('10.00');

        $purchasePrice = new ProductPurchasePrice(
            id: '44444444-4444-4444-4444-444444444490',
            company: $company,
            product: $product,
            effectiveFrom: new \DateTimeImmutable('2026-03-21'),
            priceAmount: 125050,
            priceCurrency: 'RUB',
            note: 'Цена поставщика по новому контракту',
        );

        $em->persist($owner);
        $em->persist($company);
        $em->persist($product);
        $em->persist($purchasePrice);
        $em->flush();
        $em->clear();

        $loaded = $this->em()->getRepository(ProductPurchasePrice::class)->find('44444444-4444-4444-4444-444444444490');

        self::assertInstanceOf(ProductPurchasePrice::class, $loaded);
        self::assertSame('11111111-1111-1111-1111-111111111190', $loaded->getCompany()->getId());
        self::assertSame('33333333-3333-3333-3333-333333333390', $loaded->getProduct()->getId());
        self::assertSame('2026-03-21', $loaded->getEffectiveFrom()->format('Y-m-d'));
        self::assertNull($loaded->getEffectiveTo());
        self::assertSame(125050, $loaded->getPriceAmount());
        self::assertSame('RUB', $loaded->getPriceCurrency());
        self::assertSame('Цена поставщика по новому контракту', $loaded->getNote());
    }

}
