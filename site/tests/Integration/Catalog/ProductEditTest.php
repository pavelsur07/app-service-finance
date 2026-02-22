<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductStatus;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class ProductEditTest extends WebTestCaseBase
{
    public function testEditReturns404ForProductFromAnotherCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();

        $owner = UserBuilder::aUser()->withEmail('owner-edit-404@example.test')->build();
        $activeCompany = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111160')
            ->withOwner($owner)
            ->withName('Active Company')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111161')
            ->withOwner($owner)
            ->withName('Other Company')
            ->build();

        $foreignProduct = $this->makeProduct(
            id: '33333333-3333-3333-3333-333333333360',
            company: $otherCompany,
            name: 'Foreign Product',
            sku: 'SKU-FOREIGN'
        );

        $em->persist($owner);
        $em->persist($activeCompany);
        $em->persist($otherCompany);
        $em->persist($foreignProduct);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $activeCompany);

        $client->request('GET', '/catalog/products/'.$foreignProduct->getId().'/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testEditUpdatesProductFields(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();

        $owner = UserBuilder::aUser()->withEmail('owner-edit-update@example.test')->build();
        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111170')
            ->withOwner($owner)
            ->withName('Edit Company')
            ->build();

        $product = $this->makeProduct(
            id: '33333333-3333-3333-3333-333333333370',
            company: $company,
            name: 'Old Product Name',
            sku: 'SKU-OLD'
        )
            ->setDescription('Old description')
            ->setStatus(ProductStatus::ACTIVE)
            ->setPurchasePrice('10.00');

        $em->persist($owner);
        $em->persist($company);
        $em->persist($product);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $crawler = $client->request('GET', '/catalog/products/'.$product->getId().'/edit');
        self::assertResponseIsSuccessful();

        $skuInput = $crawler->filter('input[name="product[sku]"]');
        self::assertCount(1, $skuInput);
        self::assertSame('disabled', $skuInput->attr('disabled'));

        $client->submit($crawler->selectButton('Save')->form([
            'product[name]' => 'Updated Product Name',
            'product[status]' => ProductStatus::DISCONTINUED->value,
            'product[description]' => 'Updated description',
            'product[purchasePrice]' => '42.50',
        ]));

        self::assertResponseRedirects('/catalog/products/'.$product->getId());

        $em->clear();
        $updatedProduct = $this->em()->getRepository(Product::class)->find($product->getId());

        self::assertInstanceOf(Product::class, $updatedProduct);
        self::assertSame('Updated Product Name', $updatedProduct->getName());
        self::assertSame('SKU-OLD', $updatedProduct->getSku());
        self::assertSame(ProductStatus::DISCONTINUED, $updatedProduct->getStatus());
        self::assertSame('Updated description', $updatedProduct->getDescription());
        self::assertSame('42.50', $updatedProduct->getPurchasePrice());
    }

    private function makeProduct(string $id, Company $company, string $name, string $sku): Product
    {
        return (new Product($id, $company))
            ->setName($name)
            ->setSku($sku)
            ->setPurchasePrice('100.00');
    }

    private function loginWithActiveCompany($client, object $owner, Company $company): void
    {
        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();
    }
}
