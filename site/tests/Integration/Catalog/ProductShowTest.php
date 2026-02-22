<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class ProductShowTest extends WebTestCaseBase
{
    public function testShowReturns200ForProductFromActiveCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();

        $owner = UserBuilder::aUser()->withEmail('owner-show@example.test')->build();
        $activeCompany = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111130')
            ->withOwner($owner)
            ->withName('Active Company')
            ->build();

        $product = $this->makeProduct(
            id: '33333333-3333-3333-3333-333333333333',
            company: $activeCompany,
            name: 'Visible Product',
            sku: 'SKU-VISIBLE'
        );

        $em->persist($owner);
        $em->persist($activeCompany);
        $em->persist($product);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $activeCompany->getId());
        $session->save();

        $client->request('GET', '/catalog/products/'.$product->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Visible Product', (string) $client->getResponse()->getContent());
    }

    public function testShowReturns404ForProductFromAnotherCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();

        $owner = UserBuilder::aUser()->withEmail('owner-show-404@example.test')->build();
        $activeCompany = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111140')
            ->withOwner($owner)
            ->withName('Active Company')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111141')
            ->withOwner($owner)
            ->withName('Other Company')
            ->build();

        $hiddenProduct = $this->makeProduct(
            id: '33333333-3333-3333-3333-333333333334',
            company: $otherCompany,
            name: 'Hidden Product',
            sku: 'SKU-HIDDEN'
        );

        $em->persist($owner);
        $em->persist($activeCompany);
        $em->persist($otherCompany);
        $em->persist($hiddenProduct);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $activeCompany->getId());
        $session->save();

        $client->request('GET', '/catalog/products/'.$hiddenProduct->getId());

        self::assertResponseStatusCodeSame(404);
    }

    private function makeProduct(string $id, Company $company, string $name, string $sku): Product
    {
        return (new Product($id, $company))
            ->setName($name)
            ->setSku($sku)
            ->setPurchasePrice('100.00');
    }
}
