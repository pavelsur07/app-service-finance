<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceQuality;

use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class DataQualityPageTest extends WebTestCaseBase
{
    private const URL = '/marketplace/economics/quality';
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000993';
    private const OTHER_ID = '11111111-1111-1111-1111-000000000994';

    public function testShowsLinksAndOnlyOwnProblems(): void
    {
        $client = static::createClient();
        $owner = $this->seed();
        $yesterday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->modify('-1 day')->format('Y-m-d');
        $before = (new \DateTimeImmutable($yesterday))->modify('-1 day')->format('Y-m-d');
        foreach ([self::COMPANY_ID, self::OTHER_ID] as $company) {
            $this->day($company, $before, 100000);
            $this->day($company, $yesterday, 50000);
        }
        // у своей компании витрина за вчера отстала на 1 ₽; у чужой — потерян канон позавчера
        $this->db()->executeStatement('UPDATE me_day_line SET amount_minor = amount_minor - 100 WHERE company_id = ? AND operation_date = ?', [self::COMPANY_ID, $yesterday]);
        $this->db()->executeStatement('DELETE FROM mo_operation WHERE company_id = ? AND operation_date = ?', [self::OTHER_ID, $before]);

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $crawler = $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-ui-mode="legacy"]');
        self::assertSelectorTextContains('nav[aria-label="Отчёт"] .nav-link.active[aria-current="page"]', 'Качество данных');
        self::assertSame('/marketplace/economics', $crawler->filter('nav[aria-label="Отчёт"] a')->first()->attr('href'));
        self::assertSame('/marketplace/economics/dynamics', $crawler->filter('nav[aria-label="Отчёт"] a')->eq(1)->attr('href'));
        self::assertSelectorCount(2, '[data-economics-table] .table.table-vcenter.card-table');
        $cabinet = $crawler->filter('tbody')->first()->filter('tr')->each(static fn ($r): string => $r->text());
        self::assertCount(1, $cabinet);
        self::assertStringContainsString('OZON · cab-'.substr(self::COMPANY_ID, -3), $cabinet[0]);
        self::assertMatchesRegularExpression('/1\s500,00/u', $cabinet[0], 'факты за 2 дня');
        $problems = $crawler->filter('tbody')->last()->filter('tr')->each(static fn ($r): string => $r->text());
        self::assertCount(1, $problems, 'чужое расхождение не видно');
        self::assertStringContainsString('Канон ≠ витрина', $problems[0]);
        self::assertStringContainsString('−1,00', $problems[0]);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', self::URL);

        self::assertResponseRedirects();
    }

    public function testEmptyAndReconciledStates(): void
    {
        $client = static::createClient();
        $owner = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $client->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section.card', 'Кабинетов в новом контуре нет');

        $yesterday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->modify('-1 day')->format('Y-m-d');
        $this->day(self::COMPANY_ID, $yesterday, 100000);
        $client->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section.card:last-child', 'Расхождений нет.');
        self::assertSelectorCount(1, '[data-economics-table] table');
    }

    private function seed(): User
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('mq@example.test')->build();
        $other = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('mq-other@example.test')->build();
        $em = $this->em();
        $em->persist($owner);
        $em->persist($other);
        $em->persist(CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build());
        $em->persist(CompanyBuilder::aCompany()->withId(self::OTHER_ID)->withOwner($other)->build());
        $em->flush();

        return $owner;
    }

    /** Один день во всех звеньях: факты, перевод, канон и витрина по одной продаже. */
    private function day(string $company, string $date, int $amount): void
    {
        $db = $this->db();
        $cabinet = 'cab-'.substr($company, -3);
        $db->insert('sf_period', ['id' => Uuid::uuid7()->toString(), 'company_id' => $company, 'connection_ref' => $cabinet, 'resource_type' => 'ozon.finance.accrual-by-day',
            'period_key' => $date, 'current_revision' => 1, 'fact_count' => 1, 'updated_at' => '2026-11-14 10:00:00']);
        $db->insert('moz_day', ['company_id' => $company, 'connection_ref' => $cabinet, 'operation_date' => $date, 'facts_revision' => 1, 'ads_revision' => 0,
            'rules_version' => \App\MarketplaceOzon\Domain\AccrualByDayTranslator::RULES_VERSION, 'fact_count' => 1, 'total_minor' => $amount, 'canon_minor' => $amount,
            'quarantine_minor' => 0, 'currency' => 'RUB', 'unresolved_listing_count' => 0, 'translated_at' => '2026-11-14 10:00:00']);
        $db->insert('mo_operation', ['id' => Uuid::uuid7()->toString(), 'company_id' => $company, 'channel' => 'ozon', 'connection_ref' => $cabinet, 'operation_date' => $date,
            'source_type' => 'ozon:field:sale_amount', 'kind' => 'sale', 'amount_minor' => $amount, 'currency' => 'RUB', 'source_refs' => '[]', 'rules_version' => 'test', 'created_at' => '2026-11-14 10:00:00']);
        $db->insert('me_day_line', ['id' => Uuid::uuid7()->toString(), 'company_id' => $company, 'channel' => 'ozon', 'connection_ref' => $cabinet, 'operation_date' => $date,
            'measure' => 'sale', 'level' => 'revenue', 'amount_minor' => $amount, 'quantity' => 1]);
        $db->insert('me_day', ['company_id' => $company, 'channel' => 'ozon', 'connection_ref' => $cabinet, 'operation_date' => $date, 'rules_version' => 'economics-2',
            'canon_minor' => $amount, 'unallocated_minor' => 0, 'cost_price_minor' => 0, 'without_cost_price' => 0, 'line_count' => 1, 'currency' => 'RUB', 'refreshed_at' => '2026-11-14 10:00:00']);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
