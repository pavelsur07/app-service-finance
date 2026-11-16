<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplacePlProjection;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Enum\PLFlow;
use App\MarketplacePlProjection\Application\Action\SyncTemplateAction;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class PlProjectionPageTest extends WebTestCaseBase
{
    private const URL = '/marketplace/economics/pl?from=2026-11-01&to=2026-11-10';
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000981';
    private const OTHER_ID = '11111111-1111-1111-1111-000000000982';

    public function testShowsTreeFromCanonWithUnmappedAndExcludedAndOnlyOwnData(): void
    {
        $client = static::createClient();
        $owner = $this->seed();
        $this->op(self::COMPANY_ID, 'ozon', 'sale', 'ozon:field:sale_amount', 100000);
        $this->op(self::COMPANY_ID, 'ozon', 'commission', 'ozon:field:commission', -15000);
        $this->op(self::COMPANY_ID, 'ozon', 'logistics_to', 'ozon:service:32', -5000);
        $this->op(self::COMPANY_ID, 'ozon', 'spp', 'ozon:field:bonus', 20000);
        $this->op(self::COMPANY_ID, 'ozon', 'sale', 'ozon:field:sale_amount', 77700, '2026-11-11');
        $this->op(self::OTHER_ID, 'ozon', 'sale', 'ozon:field:sale_amount', 999900);
        foreach ([[self::COMPANY_ID, '2026-11-05', -30000, 1], [self::COMPANY_ID, '2026-11-11', -77700, 0], [self::OTHER_ID, '2026-11-05', -99900, 0]] as [$company, $date, $cost, $without]) {
            static::getContainer()->get(Connection::class)->insert('me_day', ['company_id' => $company, 'channel' => 'ozon', 'connection_ref' => 'cab-1', 'operation_date' => $date,
                'rules_version' => 'economics-2', 'canon_minor' => 0, 'unallocated_minor' => 0, 'cost_price_minor' => $cost, 'without_cost_price' => $without, 'line_count' => 1,
                'currency' => 'RUB', 'refreshed_at' => '2026-11-14 10:00:00']);
        }
        (static::getContainer()->get(SyncTemplateAction::class))(self::COMPANY_ID, true);

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $crawler = $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav[aria-label="Отчёт"] .nav-link.active[aria-current="page"]', 'ОПиУ');
        $revenue = $crawler->filter('tr[data-row-code="REV_NOT_SPP"]')->text();
        self::assertMatchesRegularExpression('/1\s000,00.*0,00.*1\s000,00/u', $revenue, 'новый, текущий, разница');
        self::assertStringContainsString('(150,00)', $crawler->filter('tr[data-row-code="COGS_MP_COMMISSION"]')->text());
        self::assertMatchesRegularExpression('/800,00/u', $crawler->filter('tr[data-row-code="REV_SPP_SALES"]')->text(), 'продажа 1000 − СПП 200');
        self::assertStringContainsString('(300,00)', $crawler->filter('tr[data-row-code="COGS_PRODUCT_REV"]')->text());
        self::assertSelectorTextContains('[data-pl-no-cost]', 'в 1 дн.');
        self::assertSelectorNotExists('[data-pl-missing-codes]');
        self::assertStringNotContainsString('777', $crawler->filter('[data-pl-projection]')->text(), 'день вне периода');
        self::assertStringNotContainsString('999', $crawler->text(), 'чужая компания');

        $identity = $crawler->filter('[data-pl-identity]')->text();
        self::assertMatchesRegularExpression('/Всего операций за период: 1\s000,00 ₽/u', $identity);
        self::assertMatchesRegularExpression('/На статьи ОПиУ: 850,00 ₽; исключено из ОПиУ: 200,00 ₽; не сопоставлено: −50,00 ₽/u', $identity);
        self::assertStringContainsString('ozon:service:32', $crawler->filter('[data-pl-lines="unmapped"]')->text());
        self::assertStringContainsString('ozon:field:bonus', $crawler->filter('[data-pl-lines="excluded"]')->text());

        $crawler = $client->request('GET', self::URL.'&channel=wildberries');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-pl-projection] tbody', 'За период нет сумм.');
        self::assertSelectorNotExists('[data-pl-lines]');
    }

    public function testBadPeriodFallsBackToCurrentMonthWithMessage(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seed());
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $client->request('GET', '/marketplace/economics/pl?from=2026-11-10&to=2026-11-01');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'Показан текущий месяц');

        $client->request('GET', '/marketplace/economics/pl?from=мусор&channel=нет');
        self::assertResponseIsSuccessful();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', self::URL);

        self::assertResponseRedirects();
    }

    private function seed(): User
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('mpp@example.test')->build();
        $other = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('mpp-other@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $em = $this->em();
        $em->persist($owner);
        $em->persist($other);
        $em->persist($company);
        $em->persist(CompanyBuilder::aCompany()->withId(self::OTHER_ID)->withOwner($other)->build());
        $em->persist($this->category($company, 'REV_NOT_SPP', PLFlow::INCOME));
        $em->persist($this->category($company, 'COGS_MP_COMMISSION', PLFlow::EXPENSE));
        $em->persist($this->category($company, 'REV_SPP_SALES', PLFlow::INCOME));
        $em->persist($this->category($company, 'COGS_PRODUCT_REV', PLFlow::EXPENSE));
        $em->flush();

        return $owner;
    }

    private function category(Company $company, string $code, PLFlow $flow): object
    {
        return PLCategoryBuilder::aPLCategory()->forCompany($company)->withName($code)->withCode($code)->withFlow($flow)->build();
    }

    private function op(string $company, string $channel, string $kind, string $sourceType, int $amount, string $date = '2026-11-05'): void
    {
        static::getContainer()->get(Connection::class)->insert('mo_operation', ['id' => Uuid::uuid7()->toString(), 'company_id' => $company, 'channel' => $channel,
            'connection_ref' => 'cab-1', 'operation_date' => $date, 'source_type' => $sourceType, 'kind' => $kind, 'amount_minor' => $amount, 'currency' => 'RUB',
            'source_refs' => '[]', 'rules_version' => 'test', 'created_at' => '2026-11-14 10:00:00']);
    }
}
