<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class ProductIndexTest extends WebTestCaseBase
{
    public function testShowsOnlyProductsFromActiveCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withEmail('owner-products@example.test')
            ->build();
        $activeCompany = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111110')
            ->withOwner($owner)
            ->withName('Active Company')
            ->build();
        $anotherCompany = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111120')
            ->withOwner($owner)
            ->withName('Another Company')
            ->build();

        $activeProduct = $this->makeProduct(
            id: '33333333-3333-3333-3333-333333333331',
            company: $activeCompany,
            name: 'Active Product',
            sku: 'SKU-ACTIVE'
        );
        $otherProduct = $this->makeProduct(
            id: '33333333-3333-3333-3333-333333333332',
            company: $anotherCompany,
            name: 'Other Product',
            sku: 'SKU-OTHER'
        );

        $em->persist($owner);
        $em->persist($activeCompany);
        $em->persist($anotherCompany);
        $em->persist($activeProduct);
        $em->persist($otherProduct);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $activeCompany->getId());
        $session->save();

        $crawler = $client->request('GET', '/catalog/products');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Active Product', $client->getResponse()->getContent());
        self::assertStringNotContainsString('Other Product', $client->getResponse()->getContent());
        self::assertSame(1, $crawler->filter('table tbody tr')->count());
    }

    private function makeProduct(string $id, Company $company, string $name, string $sku): Product
    {
        return (new Product($id, $company))
            ->setName($name)
            ->setSku($sku)
            ->setPurchasePrice('100.00');
    }
}
