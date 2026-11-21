<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceEconomics;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyTaxProfileBuilder;
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
        static::getContainer()->get(Connection::class)->insert('me_day_line', [
            'id' => Uuid::uuid7()->toString(), 'company_id' => Uuid::uuid7()->toString(), 'channel' => 'ozon', 'connection_ref' => 'foreign-cabinet',
            'operation_date' => '2026-11-10', 'measure' => 'sale', 'level' => 'revenue', 'amount_minor' => 99999999, 'quantity' => 999,
        ]);

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $period = ['from' => '2026-11-10', 'to' => '2026-11-17'];

        $crawler = $client->request('GET', self::URL, $period + ['step' => 'week']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-economics-table] .table.table-vcenter.card-table');
        self::assertSelectorExists('section.card.card-body svg[role="img"]');
        $headers = $crawler->filter('thead th')->each(static fn ($th): string => $th->text());
        self::assertContains('НДС к уплате, ₽', $headers);
        self::assertContains('СМ3, %', $headers);
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

        $crawler = $client->request('GET', self::URL, $period + ['step' => 'month', 'alloc' => '0']);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertStringStartsWith('10.11 – 17.11.2026', $crawler->filter('tbody tr')->first()->text());
        self::assertSelectorNotExists('th:contains("СМ3")');
        self::assertMatchesRegularExpression('/2\s200,00/u', $crawler->filter('tbody tr')->last()->text());

        $client->request('GET', self::URL, $period + ['format' => 'xlsx']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testChannelFilterNarrowsSkuAndDynamicsAlike(): void
    {
        $client = static::createClient();
        [$owner, $ozonId] = $this->seed();
        $company = $this->em()->find(Company::class, self::COMPANY_ID);
        self::assertNotNull($company);
        $wb = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::WILDBERRIES)->withMarketplaceSku('NM-992')->withName('Платье')->build();
        $this->em()->persist($wb);
        $this->em()->flush();
        $wbId = (string) $wb->getId();
        $this->line('2026-11-10', $ozonId, 'sale', 'revenue', 100000, 1);
        $this->line('2026-11-10', $wbId, 'sale', 'revenue', 300000, 3, 'wildberries', 'wb-cabinet');
        $this->line('2026-11-11', null, 'subscription', 'overhead', -5000, null, 'wildberries', 'wb-cabinet');
        $this->day('2026-11-10');
        $this->day('2026-11-10', 'wb-cabinet', 'wildberries');
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $period = ['from' => '2026-11-10', 'to' => '2026-11-11'];

        $crawler = $client->request('GET', '/marketplace/economics', $period);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="channel"] option[value="wildberries"]');
        self::assertCount(1, $crawler->filter('tbody tr:contains("NM-992") [data-channel="wildberries"]'), 'метка канала у SKU');

        $crawler = $client->request('GET', '/marketplace/economics', $period + ['channel' => 'wildberries']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="channel"] option[value="wildberries"][selected]');
        $rows = $crawler->filter('tbody tr')->each(static fn ($r): string => $r->text());
        self::assertSame([], array_values(array_filter($rows, static fn (string $r): bool => str_contains($r, 'SKU-992'))), 'Ozon отфильтрован');
        $skuTotal = $crawler->filter('tbody tr:contains("Итого")')->last()->text();
        // WB: 3000 выручки − 50 подписки = 2950 полная
        self::assertMatchesRegularExpression('/2\s950,00/u', $skuTotal);

        $crawler = $client->request('GET', self::URL, $period + ['channel' => 'wildberries', 'step' => 'week']);
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/2\s950,00/u', $crawler->filter('tbody tr')->last()->text(), 'итог R2 = R1 канала');

        $client->request('GET', self::URL, $period + ['channel' => 'avito']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testVatTotalsMatchSkuReportForEveryStepAcrossMonthsWithDifferentRates(): void
    {
        $client = static::createClient();
        [$owner, $first] = $this->seed();
        $company = $this->em()->find(Company::class, self::COMPANY_ID);
        self::assertNotNull($company);
        $second = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('SKU-992-2')->withName('Юбка')->build();
        $this->em()->persist($second);
        $this->em()->persist(CompanyTaxProfileBuilder::aProfile()->forCompany(self::COMPANY_ID)->with(TaxRegime::USN, VatMode::EXEMPT)->validFrom(null)->build());
        $this->em()->persist(CompanyTaxProfileBuilder::aProfile()->withId('01930000-0000-7000-8000-00000000a002')->forCompany(self::COMPANY_ID)->with(TaxRegime::USN, VatMode::USN_5)->validFrom('2026-12-01')->build());
        $this->em()->flush();
        // пн 30.11 — без НДС; неделя 30.11–06.12 пересекает границу месяцев
        $this->line('2026-11-30', $first, 'sale', 'revenue', 10500, 1);
        // по 10 коп. на SKU: 10 × 5 / 105 → 0 у каждого, сумма шага (20 коп. → 1 коп.) НДС не даёт
        $this->line('2026-12-01', $first, 'sale', 'revenue', 10, 1);
        $this->line('2026-12-01', (string) $second->getId(), 'sale', 'revenue', 10, 1);
        // 210 − 105 СПП = 105 ₽ → НДС 5 ₽
        $this->line('2026-12-02', $first, 'sale', 'revenue', 21000, 1);
        $this->line('2026-12-02', $first, 'spp', 'info', 10500);
        $this->day('2026-12-01');
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $period = ['from' => '2026-11-30', 'to' => '2026-12-02'];

        // $merged — ячеек итога, объединённых colspan (в R1 «Итого» занимает SKU и наименование)
        $vat = static function ($crawler, int $merged = 0): string {
            // R1 — двухуровневая шапка: полное название в title нижней ячейки, группы не считаются
            $headers = $crawler->filter('thead th:not(.me-col-group)')->each(static fn ($th): string => $th->attr('title') ?? $th->text());
            $cell = $crawler->filter('tr.me-totals')->last()->filter('td')->eq((int) array_search('НДС к уплате, ₽', $headers, true) - $merged);

            return str_replace(["\u{a0}", '−'], [' ', '-'], $cell->text());
        };
        foreach (['day', 'week', 'month'] as $step) {
            self::assertSame('-5,00', $vat($client->request('GET', self::URL, $period + ['step' => $step])), $step);
        }
        self::assertSame('-5,00', $vat($client->request('GET', '/marketplace/economics', $period), 1), 'R1');
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

    public function testLegacyShellPreservesStepAndReturnLink(): void
    {
        $client = static::createClient();
        [$owner] = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $this->day('2026-11-10');
        $this->day('2026-11-11', 'cabinet-2');
        $params = ['connection' => 'cabinet-2', 'from' => '2026-11-10', 'to' => '2026-11-18', 'q' => 'SKU-992', 'alloc' => '0', 'step' => 'month', 'page' => '1'];
        $crawler = $client->request('GET', self::URL, $params);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-ui-mode="legacy"]');
        self::assertSelectorTextContains('nav[aria-label="Отчёт"] .nav-link.active[aria-current="page"]', 'Динамика');
        self::assertSelectorExists('select.form-select[name="step"] option[value="month"][selected]');
        self::assertSelectorExists('select[name="connection"] option[value="cabinet-2"][selected]');
        self::assertSelectorExists('select[name="alloc"] option[value="0"][selected]');
        self::assertSelectorExists('input[name="q"][value="SKU-992"]');
        self::assertSelectorExists('input[name="to"][value="2026-11-18"]');
        $href = $crawler->filter('nav[aria-label="Отчёт"] a')->first()->attr('href');
        parse_str((string) parse_url((string) $href, \PHP_URL_QUERY), $query);
        self::assertSame(array_diff_key($params, array_flip(['step', 'page'])), $query);
        self::assertSame('/marketplace/economics/quality', $crawler->filter('nav[aria-label="Отчёт"] a')->last()->attr('href'));
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

    private function line(string $date, ?string $listingId, string $measure, string $level, int $amount, ?int $qty = null, string $channel = 'ozon', string $connection = 'cabinet-1'): void
    {
        static::getContainer()->get(Connection::class)->insert('me_day_line', [
            'id' => Uuid::uuid7()->toString(), 'company_id' => self::COMPANY_ID, 'channel' => $channel, 'connection_ref' => $connection,
            'operation_date' => $date, 'listing_id' => $listingId, 'measure' => $measure, 'level' => $level, 'amount_minor' => $amount, 'quantity' => $qty,
        ]);
    }

    private function day(string $date, string $connection = 'cabinet-1', string $channel = 'ozon'): void
    {
        static::getContainer()->get(Connection::class)->insert('me_day', [
            'company_id' => self::COMPANY_ID, 'channel' => $channel, 'connection_ref' => $connection, 'operation_date' => $date, 'rules_version' => 'economics-2',
            'canon_minor' => 0, 'unallocated_minor' => 0, 'cost_price_minor' => 0, 'without_cost_price' => 0, 'line_count' => 0, 'currency' => 'RUB', 'refreshed_at' => '2026-11-14 10:00:00',
        ]);
    }
}
