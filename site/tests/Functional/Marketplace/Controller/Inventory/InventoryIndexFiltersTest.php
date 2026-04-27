<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller\Inventory;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class InventoryIndexFiltersTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-0000000000fa';

    public function testRendersIndexWithoutFilters(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedCompanyWithListing($client);
        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/inventory');

        self::assertResponseIsSuccessful();
    }

    public function testRendersIndexWithMarketplaceFilterSelected(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedCompanyWithListing($client);
        $this->loginWithActiveCompany($client, $owner, $company);

        $crawler = $client->request('GET', '/marketplace/inventory?marketplace=ozon');

        self::assertResponseIsSuccessful();
        $selectedOzon = $crawler->filter('select[name="marketplace"] option[value="ozon"][selected]');
        self::assertGreaterThan(0, $selectedOzon->count(), 'Expected ozon option to be selected');
    }

    public function testRendersIndexWithSearchInputValue(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedCompanyWithListing($client);
        $this->loginWithActiveCompany($client, $owner, $company);

        $crawler = $client->request('GET', '/marketplace/inventory?q=test');

        self::assertResponseIsSuccessful();
        $input = $crawler->filter('input[name="q"]');
        self::assertGreaterThan(0, $input->count(), 'Expected q input on page');
        self::assertSame('test', $input->attr('value'));
    }

    public function testGracefulFallbackOnArrayMarketplaceParam(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedCompanyWithListing($client);
        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/inventory?marketplace[]=foo');

        self::assertResponseIsSuccessful();
    }

    public function testGracefulFallbackOnArrayQParam(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedCompanyWithListing($client);
        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/inventory?q[]=foo');

        self::assertResponseIsSuccessful();
    }

    public function testGracefulFallbackOnArrayPageParam(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedCompanyWithListing($client);
        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/inventory?page[]=foo');

        self::assertResponseIsSuccessful();
    }

    public function testEmptyQStringIsIgnored(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedCompanyWithListing($client);
        $this->loginWithActiveCompany($client, $owner, $company);

        $crawler = $client->request('GET', '/marketplace/inventory?q=');

        self::assertResponseIsSuccessful();
        $resetLink = $crawler->filter('a:contains("Сбросить")');
        self::assertSame(0, $resetLink->count(), 'Empty q should not trigger Reset link');
    }

    public function testEmptySearchResultShowsFilteredEmptyState(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedCompanyWithListing($client);
        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/inventory?q=zzznomatchzzz');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'под текущие фильтры');
        self::assertSelectorTextNotContains('table', 'Добавьте подключение');
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function seedCompanyWithListing(KernelBrowser $client): array
    {
        $owner = UserBuilder::aUser()
            ->withEmail('inventory-filters@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->withName('Inventory Filters Co')
            ->build();

        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('sku-filters-1')
            ->build();
        $listing->setName('Filter test listing');

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($listing);
        $em->flush();

        return [$owner, $company];
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();
    }
}
