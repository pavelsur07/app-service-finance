<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class MarketplaceProductsPaginationTest extends WebTestCaseBase
{
    public function testProductsIndexPaginatesListings(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseDataWithListings(55);
        $this->loginWithActiveCompany($client, $user, $company);

        $crawler = $client->request('GET', '/marketplace/products');
        self::assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('table tbody tr'));
        self::assertStringContainsString('55', $crawler->filter('.card-footer')->text());

        $crawler = $client->request('GET', '/marketplace/products?page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('table tbody tr'));
    }

    public function testProductsIndexShowsEmptyStateWithoutListings(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseDataWithListings(0);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', '/marketplace/products');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table tbody', 'Нет товаров');
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    private function seedBaseDataWithListings(int $count): array
    {
        $user = UserBuilder::aUser()->withEmail('products-pagination@test.local')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);

        for ($i = 1; $i <= $count; ++$i) {
            $em->persist(
                MarketplaceListingBuilder::aListing()
                    ->forCompany($company)
                    ->withIndex($i)
                    ->withMarketplaceSku(sprintf('SKU-%04d', $i))
                    ->build()
            );
        }
        $em->flush();

        return [$user, $company];
    }
}
