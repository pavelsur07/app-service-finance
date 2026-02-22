<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductStatus;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class ProductCreateTest extends WebTestCaseBase
{
    public function testCreateSuccess(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('owner-create@example.test')->build();
        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111150')
            ->withOwner($owner)
            ->withName('Create Company')
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $crawler = $client->request('GET', '/catalog/products/new');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Save')->form([
            'product[name]' => 'New Product',
            'product[sku]' => 'SKU-NEW-001',
            'product[status]' => ProductStatus::ACTIVE->value,
            'product[description]' => 'Description text',
            'product[purchasePrice]' => '199.90',
        ]));

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $product = $this->em()->getRepository(Product::class)->findOneBy([
            'company' => $company,
            'sku' => 'SKU-NEW-001',
        ]);

        self::assertInstanceOf(Product::class, $product);
        self::assertSame('New Product', $product->getName());
    }

    public function testDuplicateSkuShowsFormError(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('owner-create-duplicate@example.test')->build();
        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111151')
            ->withOwner($owner)
            ->withName('Duplicate Company')
            ->build();

        $existingProduct = (new Product('33333333-3333-3333-3333-333333333335', $company))
            ->setName('Existing Product')
            ->setSku('SKU-DUP-001')
            ->setPurchasePrice('10.00');

        $em->persist($owner);
        $em->persist($company);
        $em->persist($existingProduct);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $crawler = $client->request('GET', '/catalog/products/new');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Save')->form([
            'product[name]' => 'Duplicate Product',
            'product[sku]' => 'SKU-DUP-001',
            'product[status]' => ProductStatus::ACTIVE->value,
            'product[purchasePrice]' => '99.00',
        ]));

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString(
            'Товар с таким SKU уже существует в активной компании.',
            (string) $client->getResponse()->getContent()
        );
    }


    public function testSkuMustBeUniquePerCompanyAtDatabaseLevel(): void
    {
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('owner-db-unique@example.test')->build();
        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111152')
            ->withOwner($owner)
            ->withName('DB Unique Company')
            ->build();

        $firstProduct = (new Product('33333333-3333-3333-3333-333333333336', $company))
            ->setName('First Product')
            ->setSku('SKU-DB-001')
            ->setPurchasePrice('10.00');

        $duplicateProduct = (new Product('33333333-3333-3333-3333-333333333337', $company))
            ->setName('Duplicate Product')
            ->setSku('SKU-DB-001')
            ->setPurchasePrice('11.00');

        $em->persist($owner);
        $em->persist($company);
        $em->persist($firstProduct);
        $em->flush();

        $em->persist($duplicateProduct);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function testSameSkuForDifferentCompaniesIsAllowedAtDatabaseLevel(): void
    {
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('owner-db-cross-company@example.test')->build();
        $companyA = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111153')
            ->withOwner($owner)
            ->withName('Company A')
            ->build();
        $companyB = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111154')
            ->withOwner($owner)
            ->withName('Company B')
            ->build();

        $productA = (new Product('33333333-3333-3333-3333-333333333338', $companyA))
            ->setName('Product A')
            ->setSku('SKU-SHARED-001')
            ->setPurchasePrice('10.00');

        $productB = (new Product('33333333-3333-3333-3333-333333333339', $companyB))
            ->setName('Product B')
            ->setSku('SKU-SHARED-001')
            ->setPurchasePrice('12.00');

        $em->persist($owner);
        $em->persist($companyA);
        $em->persist($companyB);
        $em->persist($productA);
        $em->persist($productB);
        $em->flush();

        self::assertNotNull($productA->getId());
        self::assertNotNull($productB->getId());
    }

    private function loginWithActiveCompany($client, object $owner, Company $company): void
    {
        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();
    }
}
