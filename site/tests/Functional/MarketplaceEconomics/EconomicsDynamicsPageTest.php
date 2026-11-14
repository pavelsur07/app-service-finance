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

final class EconomicsDynamicsPageTest extends WebTestCaseBase
{
    private const URL = '/marketplace/economics/dynamics';
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000992';

    public function testStepsCoverPeriodAndTotalsMatchSkuReport(): void
    {
        $client = static::createClient();
        [$owner, $listingId] = $this->seed();
        // пн 09.11 и вт 10.11 — одна неделя, пн 16.11 — следующая; без SKU — подписка
        $this->line('2026-11-09', $listingId, 'sale', 'revenue', 100000, 1);
        $this->line('2026-11-10', $listingId, 'sale', 'revenue', 200000, 2);
        $this->line('2026-11-10', $listingId, 'ads_allocated', 'md2', -30000);
        $this->line('2026-11-16', $listingId, 'sale', 'revenue', 50000, 1);
        $this->line('2026-11-16', null, 'subscription', 'overhead', -8000);
        $this->day('2026-11-10');

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $period = ['from' => '2026-11-10', 'to' => '2026-11-17'];

        $crawler = $client->request('GET', self::URL, $period + ['step' => 'week']);
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody tr')->each(static fn ($r): string => $r->text());
        self::assertCount(3, $rows, 'две недели + итог');
        // неделя обрезана началом периода: 09.11 не входит
        self::assertStringStartsWith('10.11 – 15.11.2026', $rows[0]);
        self::assertMatchesRegularExpression('/2\s000,00/u', $rows[0]);
        self::assertStringStartsWith('16.11 – 17.11.2026', $rows[1]);
        // итог кабинета с нераспределённым: 2000 + 500 − 300 − 80 = 2120 полная
        self::assertStringStartsWith('Итого', $rows[2]);
        self::assertMatchesRegularExpression('/2\s120,00/u', $rows[2]);

        $crawler = $client->request('GET', self::URL, $period + ['step' => 'day', 'q' => 'SKU-992']);
        $rows = $crawler->filter('tbody tr')->each(static fn ($r): string => $r->text());
        self::assertCount(9, $rows, '8 дней, пустые — нулями, + итог');
        self::assertStringStartsWith('Итого по выбранным', $rows[8]);
        self::assertMatchesRegularExpression('/2\s200,00/u', $rows[8], 'с поиском — только SKU, без подписки');

        $client->request('GET', self::URL, $period + ['format' => 'xlsx']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testRejectsBadDate(): void
    {
        $client = static::createClient();
        [$owner] = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $client->request('GET', self::URL, ['from' => '10.11.2026', 'to' => '2026-11-17']);
        self::assertResponseStatusCodeSame(422);
    }

    /** @return array{0: User, 1: string} */
    private function seed(): array
    {
        $owner = UserBuilder::aUser()->withEmail('me-dyn@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('SKU-992')->withName('Юбка')->build();
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
            'company_id' => self::COMPANY_ID, 'channel' => 'ozon', 'connection_ref' => 'cabinet-1', 'operation_date' => $date, 'rules_version' => 'economics-2',
            'canon_minor' => 0, 'unallocated_minor' => 0, 'cost_price_minor' => 0, 'without_cost_price' => 0, 'line_count' => 0, 'currency' => 'RUB', 'refreshed_at' => '2026-11-14 10:00:00',
        ]);
    }
}
