<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

final class MarketplaceCostsControllerTest extends WebTestCaseBase
{
    public function testCostsPageUsesDefaultFiltersForCurrentMonthAndOzon(): void
    {
        $client = static::createClient();

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

    /**
     * Сторно хранится положительным и в учёте уменьшает затраты: в списке — плюсом,
     * в итоге «На странице» — со знаком плюс, а не ещё одной затратой.
     */
    public function testStornoIsShownAsCostReversal(): void
    {
        $client = static::createClient();

        [$owner, $company] = $this->seedCompany();
        $date = new \DateTimeImmutable('first day of this month');
        $cost = (new MarketplaceCost('44444444-4444-4444-8444-000000000301', $company, MarketplaceType::OZON))
            ->setAmount('100.00')
            ->setCostDate($date);
        $storno = (new MarketplaceCost('44444444-4444-4444-8444-000000000302', $company, MarketplaceType::OZON))
            ->setAmount('30.00')
            ->setCostDate($date)
            ->setOperationType(MarketplaceCostOperationType::STORNO);
        $this->em()->persist($cost);
        $this->em()->persist($storno);
        $this->em()->flush();
        $this->loginWithActiveCompany($client, $owner, $company);

        $crawler = $client->request('GET', '/marketplace/costs?mapped=all');

        self::assertResponseIsSuccessful();
        $amounts = $crawler->filter('table tbody td.text-end .vf-amount')->each(static fn (Crawler $n): string => $n->text());
        sort($amounts);
        self::assertSame(['+30,00', "\u{2212}100,00"], $amounts);
        self::assertStringContainsString("\u{2212}70,00\u{00A0}₽", $crawler->filter('table tfoot')->text());
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
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
