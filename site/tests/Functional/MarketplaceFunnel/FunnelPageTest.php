<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceFunnel;

use App\Company\Entity\User;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;

final class FunnelPageTest extends WebTestCaseBase
{
    private const URL = '/marketplace/funnel';
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000971';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000972';

    public function testShowsStagesConversionsAndNoDataForMissingStage(): void
    {
        $client = static::createClient();
        $owner = $this->seed();
        $this->row(self::COMPANY_ID, 'ozon', 'oz-1', '2026-11-10', 'SKU-971', ['impressions' => 1000, 'card_views' => 200, 'cart_adds' => 40, 'orders' => 10, 'revenue_minor' => 1500000, 'buyouts' => 8]);
        $this->row(self::COMPANY_ID, 'ozon', 'oz-1', '2026-11-11', 'SKU-971', ['impressions' => 1000, 'card_views' => 200, 'cart_adds' => 40, 'orders' => 10, 'revenue_minor' => 1500000, 'buyouts' => 8]);
        $this->row(self::COMPANY_ID, 'wildberries', 'wb-1', '2026-11-10', '555', ['card_views' => 50, 'cart_adds' => 5, 'orders' => 1, 'revenue_minor' => 99900, 'buyouts' => 1]);
        $this->row(self::OTHER_COMPANY_ID, 'ozon', 'oz-9', '2026-11-10', 'FOREIGN-SKU', ['impressions' => 77777, 'orders' => 7]);
        $this->day(self::COMPANY_ID, 'ozon', 'oz-1', '2026-11-12', false);

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $crawler = $client->request('GET', self::URL, ['from' => '2026-11-10', 'to' => '2026-11-12']);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('FOREIGN-SKU', $content, 'строки чужой компании не видны');
        $rows = $crawler->filter('tbody tr[data-funnel-row]');
        self::assertCount(2, $rows);
        $ozon = $rows->first()->text();
        self::assertStringContainsString('SKU-971', $ozon, 'сортировка по заказам: Ozon первым');
        self::assertStringContainsString('Платье', $ozon, 'название — из листинга');
        self::assertMatchesRegularExpression('/2\s000/u', $ozon, 'показы — сумма дней');
        self::assertStringContainsString('20,0 %', $ozon, 'показ → карточка: 400 / 2000');
        self::assertMatchesRegularExpression('/30\s000,00/u', $ozon);
        $wb = $rows->last();
        self::assertStringContainsString('555', $wb->text(), 'товар без листинга не отбрасывается');
        self::assertSame('нет данных', $wb->filter('td[data-stage="impressions"]')->text(), 'у WB нет показов — не ноль');
        self::assertStringContainsString('1 дн. без данных', $content, 'день без тарифа — в пояснении');
        self::assertSame('21', $crawler->filter('tr[data-funnel-total] td[data-stage="orders"]')->text(), 'итог заказов: 20 + 1');
    }

    public function testFiltersByChannelExportsXlsxAndRejectsBadPeriod(): void
    {
        $client = static::createClient();
        $owner = $this->seed();
        $this->row(self::COMPANY_ID, 'ozon', 'oz-1', '2026-11-10', 'SKU-971', ['impressions' => 10, 'orders' => 1]);
        $this->row(self::COMPANY_ID, 'wildberries', 'wb-1', '2026-11-10', '555', ['card_views' => 50, 'orders' => 2]);
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $crawler = $client->request('GET', self::URL, ['from' => '2026-11-10', 'to' => '2026-11-10', 'channel' => 'wildberries']);
        self::assertCount(1, $crawler->filter('tbody tr[data-funnel-row]'));
        self::assertStringNotContainsString('SKU-971', $crawler->filter('tbody')->text());

        $client->request('GET', self::URL, ['from' => '2026-11-10', 'to' => '2026-11-10', 'format' => 'xlsx']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $client->getResponse()->headers->get('Content-Type'));

        $client->request('GET', self::URL, ['from' => '2026-11-12', 'to' => '2026-11-10']);
        self::assertResponseStatusCodeSame(422);

        $client->request('GET', self::URL, ['from' => '2026-10-01', 'to' => '2026-10-02']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Данных воронки за период нет', (string) $client->getResponse()->getContent());
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', self::URL);
        self::assertResponseRedirects();
    }

    private function seed(): User
    {
        $owner = UserBuilder::aUser()->withEmail('mfn-page@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist(MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('SKU-971')->withName('Платье')->build());
        $em->flush();

        return $owner;
    }

    /**
     * @param array<string, int> $values
     */
    private function row(string $companyId, string $channel, string $connection, string $date, string $sku, array $values): void
    {
        static::getContainer()->get(Connection::class)->insert('mfn_row', [
            'company_id' => $companyId, 'channel' => $channel, 'connection_ref' => $connection, 'operation_date' => $date, 'marketplace_sku' => $sku, 'source' => 'total',
        ] + $values);
        $this->day($companyId, $channel, $connection, $date, true);
    }

    private function day(string $companyId, string $channel, string $connection, string $date, bool $available): void
    {
        static::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO mfn_day (company_id, channel, connection_ref, operation_date, facts_revision, rules_version, available, row_count, control_sum, translated_at)
             VALUES (?, ?, ?, ?, 1, '1', ?, 0, 0, '2026-11-18 10:00:00') ON CONFLICT DO NOTHING",
            [$companyId, $channel, $connection, $date, $available ? 'true' : 'false'],
        );
    }
}
