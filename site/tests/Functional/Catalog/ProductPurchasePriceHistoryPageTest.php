<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductPurchasePrice;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class ProductPurchasePriceHistoryPageTest extends WebTestCaseBase
{
    public function testShowsFullPurchasePriceHistoryInDescendingOrder(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withEmail('owner-product-history@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId('51111111-1111-1111-1111-111111111111')
            ->withOwner($owner)
            ->withName('Company Product History')
            ->build();

        $product = (new Product('52222222-2222-2222-2222-222222222222', $company))
            ->setName('Товар для истории цен')
            ->setSku('SKU-PRICE-HISTORY')
            ->setPurchasePrice('0.00');

        $oldPrice = new ProductPurchasePrice(
            id: '53333333-3333-3333-3333-333333333333',
            company: $company,
            product: $product,
            effectiveFrom: new \DateTimeImmutable('2024-01-01'),
            priceAmount: 10000,
            priceCurrency: 'RUB',
            note: 'Старая цена',
        );

        $newPrice = new ProductPurchasePrice(
            id: '54444444-4444-4444-4444-444444444444',
            company: $company,
            product: $product,
            effectiveFrom: new \DateTimeImmutable('2024-03-01'),
            priceAmount: 12000,
            priceCurrency: 'RUB',
            note: 'Новая цена',
        );

        $em->persist($owner);
        $em->persist($company);
        $em->persist($product);
        $em->persist($oldPrice);
        $em->persist($newPrice);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();

        $crawler = $client->request('GET', sprintf('/catalog/products/%s/purchase-price/history', $product->getId()));

        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table tbody tr');
        self::assertCount(2, $rows);

        $firstRow = trim((string) $rows->eq(0)->text());
        $secondRow = trim((string) $rows->eq(1)->text());

        self::assertStringContainsString('01.03.2024', $firstRow);
        self::assertStringContainsString('12000 RUB', $firstRow);
        self::assertStringContainsString('Новая цена', $firstRow);

        self::assertStringContainsString('01.01.2024', $secondRow);
        self::assertStringContainsString('10000 RUB', $secondRow);
        self::assertStringContainsString('Старая цена', $secondRow);
    }
}

