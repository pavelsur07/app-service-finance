<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceEconomics;

use App\Company\Entity\User;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class SkuEconomicsPageTest extends WebTestCaseBase
{
    private const URL = '/marketplace/economics';
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000991';

    public function testShowsSkuLevelsUnallocatedAndCabinetTotal(): void
    {
        $client = static::createClient();
        [$owner, $listingId] = $this->seed();
        $this->line('2026-11-10', $listingId, 'sale', 'revenue', 300000, 3);
        $this->line('2026-11-10', $listingId, 'cost_price', 'cost_price', -90000, 3);
        $this->line('2026-11-10', $listingId, 'commission', 'md1', -45000);
        $this->line('2026-11-10', $listingId, 'ads_allocated', 'md2', -15000);
        $this->line('2026-11-10', $listingId, 'overhead_share', 'overhead', -5000);
        $this->line('2026-11-10', null, 'subscription', 'overhead', -8000);
        $this->line('2026-11-10', null, 'overhead_share', 'overhead', 5000);
        // прошлый период (01.11–09.11 для 10.11–18.11): СМ2 SKU = 1 000 ₽
        $this->line('2026-11-05', $listingId, 'sale', 'revenue', 100000, 1);
        $this->day('2026-11-10');

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $crawler = $client->request('GET', self::URL, ['from' => '2026-11-10', 'to' => '2026-11-18']);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('tbody tr')->first()->text();
        self::assertStringContainsString('SKU-991', $row);
        // СМ1 = 3000 − 900 − 450 = 1650; СМ2 = 1500; СМ3 = 1450
        self::assertMatchesRegularExpression('/1\s650,00/u', $row);
        self::assertMatchesRegularExpression('/1\s450,00/u', $row);
        $totals = $crawler->filter('tr.t-row--totals');
        self::assertSame(['Нераспределено', 'Итого'], $totals->each(static fn ($r): string => $r->filter('td')->first()->text()));
        // итог кабинета: 1450 − 30 нераспределённой подписки
        self::assertMatchesRegularExpression('/1\s420,00/u', $totals->last()->text());

        $client->request('GET', self::URL, ['from' => '2026-11-10', 'to' => '2026-11-18', 'alloc' => '0']);
        self::assertStringNotContainsString('СМ3', (string) $client->getResponse()->getContent());

        $client->request('GET', self::URL, ['from' => '2026-11-10', 'to' => '2026-11-18', 'format' => 'xlsx']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testRejectsTooLongPeriodAndShowsEmptyStateWithoutData(): void
    {
        $client = static::createClient();
        [$owner] = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $client->request('GET', self::URL, ['from' => '2025-01-01', 'to' => '2026-01-02']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('не больше 366 дней', (string) $client->getResponse()->getContent());

        $client->request('GET', self::URL, ['from' => '2026-11-01', 'to' => '2026-11-10']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ещё не переведены на новый контур', (string) $client->getResponse()->getContent());
    }

    /** @return array{0: User, 1: string} */
    private function seed(): array
    {
        $owner = UserBuilder::aUser()->withEmail('me-sku@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('SKU-991')->withName('Платье')->build();
        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($listing);
        $em->flush();

        return [$owner, (string) $listing->getId()];
    }

    private function line(string $date, ?string $listingId, string $measure, string $level, int $amount, ?int $qty = null): void
    {
        static::getContainer()->get(Connection::class)->insert('me_day_line', [
            'id' => Uuid::uuid7()->toString(), 'company_id' => self::COMPANY_ID, 'channel' => 'ozon', 'connection_ref' => 'cabinet-1',
            'operation_date' => $date, 'listing_id' => $listingId, 'measure' => $measure, 'level' => $level, 'amount_minor' => $amount, 'quantity' => $qty,
        ]);
    }

    private function day(string $date): void
    {
        static::getContainer()->get(Connection::class)->insert('me_day', [
            'company_id' => self::COMPANY_ID, 'channel' => 'ozon', 'connection_ref' => 'cabinet-1', 'operation_date' => $date, 'rules_version' => 'economics-1',
            'canon_minor' => 0, 'unallocated_minor' => 0, 'cost_price_minor' => 0, 'without_cost_price' => 0, 'line_count' => 0, 'currency' => 'RUB', 'refreshed_at' => '2026-11-14 10:00:00',
        ]);
    }
}
