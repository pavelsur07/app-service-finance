<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Ozon\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Enum\OzonReconciliationCheck;
use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Marketplace\Repository\OzonReconciliationRunRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Builders\Marketplace\OzonReconciliationLineBuilder;
use App\Tests\Builders\Marketplace\OzonReconciliationRunBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class OzonReconciliationControllerTest extends WebTestCaseBase
{
    private const CSRF = 'marketplace_ozon_reconciliation_run';

    public function testGuestIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/marketplace/ozon-reconciliation');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testEmptyStateWhenNoSnapshot(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        $this->login($client, $owner, $company);

        $client->request('GET', '/marketplace/ozon-reconciliation?month=2026-06');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="ozon-reconciliation-empty"]');
    }

    public function testShowsOwnSnapshotButNeverAnotherCompanys(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        [, $other] = $this->seed(2);
        $this->em()->persist(OzonReconciliationRunBuilder::aRun()->withCompanyId((string) $company->getId())->forMonth(2026, 6)->asMismatch(2)->build());
        $this->em()->persist(OzonReconciliationRunBuilder::aRun()->withIndex(2)->withCompanyId((string) $other->getId())->forMonth(2026, 7)->asMismatch(5)->build());
        $this->em()->flush();
        $this->login($client, $owner, $company);

        $client->request('GET', '/marketplace/ozon-reconciliation?month=2026-06');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="ozon-reconciliation-summary"]', 'Есть расхождения: 2');

        // Месяц чужого снимка (июль) в списке есть как обычный месяц, но данных чужой компании на нём нет.
        $client->request('GET', '/marketplace/ozon-reconciliation?month=2026-07');
        self::assertSelectorExists('[data-testid="ozon-reconciliation-empty"]');
        self::assertSelectorNotExists('[data-testid="ozon-reconciliation-summary"]');
    }

    public function testNavigationShowsReconciliationTab(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', '/marketplace/ozon-reconciliation');

        self::assertResponseIsSuccessful();
        $tab = $crawler->filter('ul.nav-tabs a.nav-link.active');
        self::assertCount(1, $tab);
        self::assertStringContainsString('Сверка Ozon', $tab->text());

        $other = $client->request('GET', '/marketplace/sales');
        self::assertSame('/marketplace/ozon-reconciliation', $other->filter('ul.nav-tabs a:contains("Сверка Ozon")')->attr('href'));
    }

    public function testMismatchVerdictShowsVisibleReasonsNotesAndDrillDownLinks(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        $companyId = (string) $company->getId();
        $run = OzonReconciliationRunBuilder::aRun()->withCompanyId($companyId)->forMonth(2026, 6)->asMismatch(1)->build();
        $line = OzonReconciliationLineBuilder::aLine()->withCompanyId($companyId)->withRunId($run->getId());
        $this->em()->persist($run);
        $this->em()->persist($line->withIndex(1)->forCheck(OzonReconciliationCheck::RAW_VS_LEDGER)->forBlock(OzonReconciliationBlock::LOGISTICS)
            ->withAmounts(11800, 10000, OzonReconciliationStatus::MISMATCH)->build());
        $this->em()->persist($line->withIndex(2)->forCheck(OzonReconciliationCheck::RAW_VS_LEDGER)->forBlock(OzonReconciliationBlock::LOGISTICS, 'ozon_logistic_direct')
            ->withAmounts(11800, 10000, OzonReconciliationStatus::MISMATCH)->build());
        $this->em()->persist($line->withIndex(3)->forCheck(OzonReconciliationCheck::REALIZATION_VS_RAW)->forBlock(OzonReconciliationBlock::SALES)
            ->withAmounts(null, 116857, OzonReconciliationStatus::NO_DATA)->build());
        $this->em()->flush();
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', '/marketplace/ozon-reconciliation?month=2026-06');

        self::assertResponseIsSuccessful();
        self::assertSame('mismatch', $crawler->filter('[data-testid="ozon-reconciliation-verdict"]')->attr('data-status'));
        self::assertStringContainsString('Есть расхождения: 1', $crawler->filter('[data-testid="ozon-reconciliation-summary"]')->text());
        self::assertStringContainsString('загружено дней 30 из 30', $crawler->filter('[data-testid="ozon-reconciliation-reasons"]')->text());

        $logistics = $crawler->filter('[data-testid="ozon-reconciliation-check-raw_vs_ledger"] [data-testid="ozon-reconciliation-block"][data-block="logistics"]');
        self::assertCount(1, $logistics);
        self::assertStringContainsString('118.00', $logistics->text());
        self::assertStringContainsString('-18.00', $logistics->text());
        self::assertStringContainsString('Расхождение', $logistics->text());
        // Блок с расхождением раскрыт, категория видна и ведёт в drill-down.
        $category = $crawler->filter('[data-testid="ozon-reconciliation-category"]');
        self::assertCount(1, $category);
        self::assertStringContainsString('show', (string) $category->attr('class'));
        self::assertStringContainsString('category=ozon_logistic_direct', (string) $category->filter('a')->attr('href'));
        self::assertStringContainsString('kind=costs&block=logistics', (string) $logistics->filter('a')->attr('href'));

        // «Реализация» не загружена: прочерк вместо нуля, без ссылки на записи.
        $sales = $crawler->filter('[data-testid="ozon-reconciliation-check-realization_vs_raw"] [data-testid="ozon-reconciliation-block"]');
        self::assertCount(1, $sales);
        self::assertStringContainsString('Нет данных', $sales->text());
        self::assertCount(0, $sales->filter('a'));
        self::assertStringContainsString('Отчёт «Реализация»: загружен', preg_replace('/\s+/', ' ', $crawler->filter('[data-testid="ozon-reconciliation-reasons"]')->text()) ?? '');
    }

    public function testNoDataAndMatchedVerdictsDoNotClaimMoreThanTheyKnow(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        $companyId = (string) $company->getId();
        $this->em()->persist(OzonReconciliationRunBuilder::aRun()->withIndex(1)->withCompanyId($companyId)->forMonth(2026, 5)->asNoData()->build());
        $this->em()->persist(OzonReconciliationRunBuilder::aRun()->withIndex(2)->withCompanyId($companyId)->forMonth(2026, 6)->build());
        $this->em()->flush();
        $this->login($client, $owner, $company);

        $client->request('GET', '/marketplace/ozon-reconciliation?month=2026-05');
        self::assertSame('no_data', $client->getCrawler()->filter('[data-testid="ozon-reconciliation-verdict"]')->attr('data-status'));
        self::assertStringContainsString('Данных недостаточно', $client->getCrawler()->filter('[data-testid="ozon-reconciliation-summary"]')->text());

        $client->request('GET', '/marketplace/ozon-reconciliation?month=2026-06');
        self::assertSame('matched', $client->getCrawler()->filter('[data-testid="ozon-reconciliation-verdict"]')->attr('data-status'));
        self::assertStringContainsString('сходятся с Ozon', $client->getCrawler()->filter('[data-testid="ozon-reconciliation-summary"]')->text());

        // Без «Реализации» заголовок не утверждает сверку с Ozon: сверен только учёт с нашими сырыми данными.
        $this->em()->persist(OzonReconciliationRunBuilder::aRun()->withIndex(3)->withCompanyId($companyId)->forMonth(2026, 7)->withoutRealization()->build());
        $this->em()->flush();
        $client->request('GET', '/marketplace/ozon-reconciliation?month=2026-07');
        $summary = $client->getCrawler()->filter('[data-testid="ozon-reconciliation-summary"]')->text();
        self::assertStringContainsString('Учёт совпадает с сырыми данными Ozon', $summary);
        self::assertStringNotContainsString('сходятся с Ozon', $summary);
        self::assertStringContainsString('не выполнена', $client->getCrawler()->filter('[data-testid="ozon-reconciliation-verdict"]')->text());
    }

    public function testRunButtonLabelDependsOnSnapshot(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        $this->em()->persist(OzonReconciliationRunBuilder::aRun()->withCompanyId((string) $company->getId())->forMonth(2026, 6)->build());
        $this->em()->flush();
        $this->login($client, $owner, $company);

        $client->request('GET', '/marketplace/ozon-reconciliation?month=2026-06');
        self::assertStringContainsString('Пересчитать сейчас', $client->getCrawler()->filter('[data-testid="ozon-reconciliation-run"]')->text());
        self::assertStringContainsString('Обновлено', $client->getCrawler()->filter('[data-testid="ozon-reconciliation-updated"]')->text());
        // Плашка прогресса есть в разметке, скрыта до отправки формы; кнопка знает свой текст ожидания.
        self::assertStringContainsString('d-none', (string) $client->getCrawler()->filter('[data-testid="ozon-reconciliation-busy"]')->attr('class'));
        self::assertSame('Идёт пересчёт…', $client->getCrawler()->filter('[data-testid="ozon-reconciliation-run-form"]')->attr('data-busy-text'));

        $client->request('GET', '/marketplace/ozon-reconciliation?month=2026-05');
        self::assertStringContainsString('Выполнить сверку', $client->getCrawler()->filter('[data-testid="ozon-reconciliation-run"]')->text());
    }

    public function testMonthListOffersLastYearEvenWithoutSnapshots(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', '/marketplace/ozon-reconciliation');

        self::assertResponseIsSuccessful();
        $values = $crawler->filter('#ozon-reconciliation-month option')->each(static fn ($o): string => (string) $o->attr('value'));
        $currentMonth = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m');
        $previousMonth = (new \DateTimeImmutable('first day of last month', new \DateTimeZone('Europe/Moscow')))->format('Y-m');
        self::assertContains($currentMonth, $values);
        self::assertContains($previousMonth, $values);
        self::assertGreaterThanOrEqual(12, count($values));
        self::assertCount(count($values), array_unique($values));
    }

    public function testRealizationStateIsShownForMonthWithoutReport(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        $this->em()->persist(OzonReconciliationRunBuilder::aRun()->withCompanyId((string) $company->getId())->forMonth(2026, 6)->withoutRealization()->build());
        $this->em()->flush();
        $this->login($client, $owner, $company);

        $client->request('GET', '/marketplace/ozon-reconciliation?month=2026-06');

        $state = $client->getCrawler()->filter('[data-testid="ozon-reconciliation-realization-state"]');
        self::assertCount(1, $state);
        self::assertSame('warning', $state->attr('data-tone'));
        self::assertStringContainsString('загрузите отчёт вручную', $state->text());
    }

    public function testInvalidMonthFallsBackWithoutError(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        $this->login($client, $owner, $company);

        foreach (['/marketplace/ozon-reconciliation?month=bad', '/marketplace/ozon-reconciliation?month[]=2026-06', '/marketplace/ozon-reconciliation?month=2026-13'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
        }
    }

    public function testRunRequiresCsrf(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        $this->login($client, $owner, $company);

        $client->request('POST', '/marketplace/ozon-reconciliation/run', ['month' => '2026-06']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], static::getContainer()->get(OzonReconciliationRunRepository::class)->findRecentByCompany((string) $company->getId()));
    }

    public function testRunCreatesSnapshotAndRedirects(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        $this->login($client, $owner, $company);

        $client->request('POST', '/marketplace/ozon-reconciliation/run', ['month' => '2026-06', '_token' => $this->csrfToken($client, self::CSRF)]);

        self::assertResponseRedirects('/marketplace/ozon-reconciliation?month=2026-06');
        $runs = static::getContainer()->get(OzonReconciliationRunRepository::class)->findRecentByCompany((string) $company->getId());
        self::assertCount(1, $runs);
        self::assertSame('2026-06-01', $runs[0]->getPeriodFrom()->format('Y-m-d'));
    }

    public function testRunRejectsInvalidMonthWithoutCreatingSnapshot(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        $this->login($client, $owner, $company);

        $client->request('POST', '/marketplace/ozon-reconciliation/run', ['month' => 'nope', '_token' => $this->csrfToken($client, self::CSRF)]);

        self::assertResponseRedirects('/marketplace/ozon-reconciliation');
        self::assertSame([], static::getContainer()->get(OzonReconciliationRunRepository::class)->findRecentByCompany((string) $company->getId()));
    }

    public function testOperationsListsOnlyOwnByDayRecordsWithPaging(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        [, $other] = $this->seed(2);
        $this->seedSales($company, 3);
        $this->seedSales($other, 2);
        $this->login($client, $owner, $company);

        $client->request('GET', '/marketplace/ozon-reconciliation/operations?month=2026-06&kind=sales&limit=2');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $client->getCrawler()->filter('[data-testid="ozon-reconciliation-operations"] tbody tr'));

        $client->request('GET', '/marketplace/ozon-reconciliation/operations?month=2026-06&kind=sales&limit=2&page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filter('[data-testid="ozon-reconciliation-operations"] tbody tr'));

        $client->request('GET', '/marketplace/ozon-reconciliation/operations?month=2026-06&kind=returns');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/marketplace/ozon-reconciliation/operations?month=2026-06&kind=costs&block=logistics&category=ozon_logistic_direct');
        self::assertResponseIsSuccessful();
    }

    public function testOperationsRejectsBadParametersWith422(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed(1);
        $this->login($client, $owner, $company);

        foreach ([
            'month=2026-06&kind=sales&limit=201',
            'month=2026-06&kind=sales&limit=0',
            'month=2026-06&kind=sales&page=0',
            'month=2026-06&kind=sales&page=99',
            'month=2026-06&kind=unknown',
            'month=2026-06&kind=costs&block=nope',
            'month=2026-06&kind=costs&category=Bad%20Code',
            'month=bad&kind=sales',
            'month=2026-06&kind=sales&limit[]=1',
        ] as $query) {
            $client->request('GET', '/marketplace/ozon-reconciliation/operations?'.$query);
            self::assertResponseStatusCodeSame(422, $query);
        }
    }

    /** @return array{0: User, 1: Company} */
    private function seed(int $index): array
    {
        $owner = UserBuilder::aUser()->withIndex($index)->withEmail(sprintf('ozon-recon-%d@example.test', $index))->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();

        return [$owner, $company];
    }

    private function login(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    private function seedSales(Company $company, int $count): void
    {
        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('700000000')->build();
        $doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('accrual_by_day')->withProcessingStatus('completed')->withPeriod(new \DateTimeImmutable('2026-06-10'), new \DateTimeImmutable('2026-06-10'))->build();
        $doc->setApiEndpoint(MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value);
        $this->em()->persist($listing);
        $this->em()->persist($doc);
        $this->em()->flush();

        $connection = $this->em()->getConnection();
        for ($i = 0; $i < $count; ++$i) {
            $connection->insert('marketplace_sales', [
                'id' => Uuid::uuid4()->toString(), 'company_id' => (string) $company->getId(), 'listing_id' => (string) $listing->getId(), 'marketplace' => 'ozon',
                'external_order_id' => sprintf('%s-%d', substr((string) $company->getId(), -4), $i), 'sale_date' => '2026-06-10', 'quantity' => 1,
                'price_per_unit' => '100.00', 'total_revenue' => '100.00', 'raw_document_id' => (string) $doc->getId(),
                'created_at' => '2026-06-30 00:00:00', 'updated_at' => '2026-06-30 00:00:00',
            ]);
        }
    }
}
