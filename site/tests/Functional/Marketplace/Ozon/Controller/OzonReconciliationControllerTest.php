<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Ozon\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\OzonReconciliationRunRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
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
        self::assertSelectorTextContains('[data-testid="ozon-reconciliation-summary"]', 'расхождений: 2');

        // Месяц чужого снимка (июль) не предлагается в выборе, а по прямой ссылке сверка «ещё не выполнялась».
        self::assertCount(0, $client->getCrawler()->filter('option[value="2026-07"]'));
        $client->request('GET', '/marketplace/ozon-reconciliation?month=2026-07');
        self::assertSelectorExists('[data-testid="ozon-reconciliation-empty"]');
        self::assertSelectorNotExists('[data-testid="ozon-reconciliation-summary"]');
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
            ->withDocumentType('accrual_by_day')->withPeriod(new \DateTimeImmutable('2026-06-10'), new \DateTimeImmutable('2026-06-10'))->build();
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
