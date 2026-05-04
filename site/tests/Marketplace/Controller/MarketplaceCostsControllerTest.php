<?php

declare(strict_types=1);

namespace App\Tests\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class MarketplaceCostsControllerTest extends WebTestCaseBase
{
    public function testCostsPageUsesDefaultFiltersForCurrentMonthAndOzon(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedCompany();
        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/costs');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();

        $expectedDateFrom = (new \DateTimeImmutable())->modify('first day of this month')->format('Y-m-d');
        $expectedDateTo = (new \DateTimeImmutable())->modify('last day of this month')->format('Y-m-d');

        self::assertSame(
            'ozon',
            $crawler->filter('select[name="marketplace"] option[selected]')->attr('value')
        );

        self::assertSame(
            $expectedDateFrom,
            $crawler->filter('input[name="date_from"]')->attr('value')
        );

        self::assertSame(
            $expectedDateTo,
            $crawler->filter('input[name="date_to"]')->attr('value')
        );
    }

    public function testCostsPageHandlesInvalidAndArrayQueryParamsWithout500(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedCompany();
        $this->loginWithActiveCompany($client, $owner, $company);

        $urls = [
            '/marketplace/costs?marketplace=bad-value&date_from=bad&date_to=bad',
            '/marketplace/costs?marketplace[]=ozon&date_from[]=2026-05-01&date_to[]=2026-05-31',
            '/marketplace/costs?mapped[]=linked',
            '/marketplace/costs?category[]=abc',
            '/marketplace/costs?category=abc',
        ];

        foreach ($urls as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
        }
    }

    /** @return array{0: User,1: Company} */
    private function seedCompany(): array
    {
        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000301')->withEmail('costs-page-owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-000000000301')->withOwner($owner)->build();

        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();

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
