<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller\Inventory;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class InventorySetCostRedirectTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000099';

    public function testRedirectsToInventoryIndexWhenRefererIsIndex(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $listing] = $this->seedCompanyAndListing('redirect-index@example.test', 'sku-redirect-1');
        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request(
            'POST',
            sprintf('/marketplace/inventory/%s/set-cost', $listing->getId()),
            [
                'price_amount' => '850.00',
                'effective_from' => '2026-04-15',
                'note' => 'unit test',
            ],
            [],
            ['HTTP_REFERER' => 'http://localhost/marketplace/inventory'],
        );

        self::assertResponseRedirects('/marketplace/inventory');
    }

    public function testRedirectsToInventoryIndexWithQueryWhenRefererHasFilter(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $listing] = $this->seedCompanyAndListing('redirect-filter@example.test', 'sku-redirect-2');
        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request(
            'POST',
            sprintf('/marketplace/inventory/%s/set-cost', $listing->getId()),
            [
                'price_amount' => '999.50',
                'effective_from' => '2026-04-16',
            ],
            [],
            ['HTTP_REFERER' => 'http://localhost/marketplace/inventory?marketplace=ozon&page=2'],
        );

        self::assertResponseRedirects('/marketplace/inventory?marketplace=ozon&page=2');
    }

    public function testRedirectsBackToHistoryWhenRefererIsHistory(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $listing] = $this->seedCompanyAndListing('redirect-history@example.test', 'sku-redirect-3');
        $this->loginWithActiveCompany($client, $owner, $company);

        $listingId = (string) $listing->getId();

        $client->request(
            'POST',
            sprintf('/marketplace/inventory/%s/set-cost', $listingId),
            [
                'price_amount' => '1234.56',
                'effective_from' => '2026-04-17',
            ],
            [],
            ['HTTP_REFERER' => sprintf('http://localhost/marketplace/inventory/%s/history', $listingId)],
        );

        self::assertResponseRedirects(sprintf('/marketplace/inventory/%s/history', $listingId));
    }

    public function testRedirectsToHistoryWhenReturnToFieldIsPresentWithoutReferer(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $listing] = $this->seedCompanyAndListing('redirect-return-to@example.test', 'sku-redirect-4');
        $this->loginWithActiveCompany($client, $owner, $company);

        $listingId = (string) $listing->getId();

        $client->request(
            'POST',
            sprintf('/marketplace/inventory/%s/set-cost', $listingId),
            [
                'price_amount' => '777.00',
                'effective_from' => '2026-04-18',
                'return_to' => 'history',
            ],
        );

        self::assertResponseRedirects(sprintf('/marketplace/inventory/%s/history', $listingId));
    }

    /**
     * @return array{0: User, 1: Company, 2: MarketplaceListing}
     */
    private function seedCompanyAndListing(string $email, string $sku): array
    {
        $owner = UserBuilder::aUser()
            ->withEmail($email)
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->withName('Inventory Redirect Co')
            ->build();

        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku($sku)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($listing);
        $em->flush();

        return [$owner, $company, $listing];
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();
    }
}
