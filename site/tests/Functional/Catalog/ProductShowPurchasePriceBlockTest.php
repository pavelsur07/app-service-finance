<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductPurchasePrice;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class ProductShowPurchasePriceBlockTest extends WebTestCaseBase
{
    public function testShowsPurchasePriceForTodayAndRequestedDate(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withEmail('owner-show-price@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId('21111111-1111-1111-1111-111111111111')
            ->withOwner($owner)
            ->withName('Company Show Price')
            ->build();

        $product = (new Product('32222222-2222-2222-2222-222222222222', $company))
            ->setName('Товар с ценой')
            ->setSku('SKU-PRICE-SHOW')
            ->setPurchasePrice('0.00');

        $purchasePrice = new ProductPurchasePrice(
            id: '43333333-3333-3333-3333-333333333333',
            company: $company,
            product: $product,
            effectiveFrom: new \DateTimeImmutable('2024-01-01'),
            priceAmount: 199900,
            priceCurrency: 'RUB',
        );

        $em->persist($owner);
        $em->persist($company);
        $em->persist($product);
        $em->persist($purchasePrice);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();

        $client->request('GET', sprintf('/catalog/products/%s?price_at=2024-02-15', $product->getId()));

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Закупочная цена', $content);
        self::assertStringContainsString('На сегодня', $content);
        self::assertStringContainsString('Цена на дату (15.02.2024)', $content);
        self::assertStringContainsString('199900 RUB', $content);
    }
}

