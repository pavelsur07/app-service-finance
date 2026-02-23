<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Entity\Product;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class ProductPurchasePriceCreateTest extends WebTestCaseBase
{
    public function testCreatesPurchasePriceWithCsrfProtectedPost(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withEmail('owner-product-price-create@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId('21111111-1111-1111-1111-111111111112')
            ->withOwner($owner)
            ->withName('Company Product Price Create')
            ->build();

        $product = (new Product('32222222-2222-2222-2222-222222222223', $company))
            ->setName('Товар для добавления цены')
            ->setSku('SKU-PRICE-CREATE')
            ->setPurchasePrice('0.00');

        $em->persist($owner);
        $em->persist($company);
        $em->persist($product);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();

        $crawler = $client->request('GET', sprintf('/catalog/products/%s', $product->getId()));
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="product_purchase_price[_token]"]')->attr('value');
        self::assertNotNull($token);

        $client->request('POST', sprintf('/catalog/products/%s/purchase-price', $product->getId()), [
            'product_purchase_price' => [
                'effectiveFrom' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                'priceAmount' => '123456',
                'currency' => 'RUB',
                'note' => 'Тестовая закупочная цена',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects(sprintf('/catalog/products/%s#purchase-price', $product->getId()));

        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Закупочная цена успешно добавлена.', $content);
        self::assertStringContainsString('123456 RUB', $content);
    }
}
