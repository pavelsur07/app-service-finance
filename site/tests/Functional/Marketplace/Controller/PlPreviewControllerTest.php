<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Integration\Marketplace\PlPreview\PlPreviewFixture;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PlPreviewControllerTest extends WebTestCaseBase
{
    private const URL = '/marketplace/pl-preview?dateFrom=2026-01-01&dateTo=2026-01-31';

    public function testGuestIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', self::URL);

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testShowsPreviewByTagsWithBlocks(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 431);
        $tag = MarketplaceListingTagBuilder::aTag()->forCompanyId((string) $fixture->company->getId())->withName('Зима')->build();
        $this->em()->persist($tag);
        $this->em()->flush();
        self::getContainer()->get(ListingTagAssignmentRepository::class)
            ->assign((string) $fixture->company->getId(), [$fixture->wbA->getId()], $tag->getId());
        $this->em()->clear();
        $this->login($client, $fixture->owner, $fixture->company);

        $crawler = $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $headers = $crawler->filter('[data-testid="pl-preview-table"] thead th')->each(static fn ($th): string => trim($th->text()));
        self::assertSame(['Статья', 'Зима', 'Общие / без тега', 'Итого'], $headers);

        $revenue = $crawler->filter('[data-testid="pl-preview-table"] tr[data-row-code="REV"] td')->each(static fn ($td): string => (string) $td->attr('title'));
        self::assertSame(['REV', '1 000,00', '300,00', '1 300,00'], $this->normalizeSpaces($revenue));
        $costs = $crawler->filter('[data-testid="pl-preview-table"] tr[data-row-code="COST"] td')->each(static fn ($td): string => (string) $td->attr('title'));
        self::assertSame(['COST', '(70,00)', '(120,00)', '(190,00)'], $this->normalizeSpaces($costs));

        self::assertSelectorTextContains('[data-testid="pl-preview-unmapped-costs"]', 'wb_no_rule');
        self::assertSelectorTextContains('[data-testid="pl-preview-unmapped-costs"]', 'Без категории');
        self::assertSelectorTextContains('[data-testid="pl-preview-excluded"]', 'wb_excluded');
        self::assertSame('yes', $crawler->filter('[data-testid="pl-preview-reconciliation"]')->attr('data-reconciled'));
        self::assertSelectorTextContains('[data-testid="pl-preview-warnings"]', 'не задан проект');
        self::assertSelectorNotExists('[data-testid="pl-preview-overlap"]');

        $tab = $crawler->filter('.card-header .nav-tabs a.active');
        self::assertStringContainsString('Предпросмотр ОПиУ', $tab->text());

        // Боковое меню: свой подпункт в «Маркетплейсах» подсвечен, «Начисления» — нет.
        $sidebar = $crawler->filter('.dropdown-menu-column a.dropdown-item.active');
        self::assertSame(['Предпросмотр ОПиУ'], $sidebar->each(static fn ($a): string => trim($a->text())));
        self::assertSame('/marketplace/pl-preview', $sidebar->attr('href'));
    }

    public function testOtherCompanySeesNothingOfThisOne(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 441);
        $this->em()->persist(MarketplaceListingTagBuilder::aTag()->forCompanyId((string) $fixture->company->getId())->withName('Чужой тег')->build());
        $owner = UserBuilder::aUser()->withIndex(900)->withEmail('pl-preview-stranger@example.test')->build();
        $stranger = CompanyBuilder::aCompany()->withIndex(900)->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($stranger);
        $this->em()->flush();
        $this->em()->clear();
        $this->login($client, $owner, $stranger);

        $crawler = $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="pl-preview-empty"]');
        self::assertSame(['Статья', 'Общие / без тега', 'Итого'], $crawler->filter('[data-testid="pl-preview-table"] thead th')->each(static fn ($th): string => trim($th->text())));
        self::assertSelectorNotExists('[data-testid="pl-preview-unmapped-costs"]');
        self::assertSelectorExists('[data-testid="pl-preview-all-mapped"]');
        self::assertSelectorNotExists('[data-testid="pl-preview-tags"]');
        self::assertStringNotContainsString('Чужой тег', (string) $client->getResponse()->getContent());

        // Чужой tagId в ссылке не даёт колонку.
        $client->request('GET', self::URL.'&tags_set=1&tags[]='.$this->em()->getConnection()->fetchOne("SELECT id FROM marketplace_listing_tags WHERE name = 'Чужой тег'"));
        self::assertStringNotContainsString('Чужой тег', (string) $client->getResponse()->getContent());
    }

    public function testEmptyRowsAreHiddenUntilAsked(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 451);
        $this->em()->clear();
        $this->login($client, $fixture->owner, $fixture->company);

        $client->request('GET', '/marketplace/pl-preview?dateFrom=2026-05-01&dateTo=2026-05-31');
        self::assertSelectorExists('[data-testid="pl-preview-empty"]');
        self::assertSelectorNotExists('tr[data-row-code="REV"]');

        $client->request('GET', '/marketplace/pl-preview?dateFrom=2026-05-01&dateTo=2026-05-31&empty=1');
        self::assertSelectorExists('tr[data-row-code="REV"]');
    }

    public function testReversedPeriodShowsMessageInsteadOfError(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 461);
        $this->em()->clear();
        $this->login($client, $fixture->owner, $fixture->company);

        $client->request('GET', '/marketplace/pl-preview?dateFrom=2026-02-01&dateTo=2026-01-01');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="pl-preview-error"]', 'Начало периода позже окончания');
        self::assertSelectorNotExists('[data-testid="pl-preview-table"]');
    }

    public function testXlsxMatchesTheScreen(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 471);
        $tag = MarketplaceListingTagBuilder::aTag()->forCompanyId((string) $fixture->company->getId())->withName('Зима')->build();
        $this->em()->persist($tag);
        $this->em()->flush();
        self::getContainer()->get(ListingTagAssignmentRepository::class)
            ->assign((string) $fixture->company->getId(), [$fixture->wbA->getId()], $tag->getId());
        $this->em()->clear();
        $this->login($client, $fixture->owner, $fixture->company);

        $crawler = $client->request('GET', self::URL);
        $href = (string) $crawler->filter('[data-testid="pl-preview-xlsx"]')->attr('href');
        self::assertStringStartsWith('/marketplace/pl-preview/xlsx?', $href);
        self::assertStringContainsString('dateFrom=2026-01-01', $href);

        $client->request('GET', $href);

        self::assertResponseIsSuccessful();
        $response = $client->getInternalResponse();
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('pl-preview_2026-01-01_2026-01-31.xlsx', (string) $client->getResponse()->headers->get('Content-Disposition'));

        $sheets = $this->readXlsx($response->getContent());
        self::assertSame(['ОПиУ по тегам', 'Не попадёт в ОПиУ', 'Исключено', 'Сверка'], array_keys($sheets));

        $report = $sheets['ОПиУ по тегам'];
        self::assertContains(['Статья', 'Зима', 'Общие / без тега', 'Итого'], $report);
        self::assertContains(['REV', 1000.0, 300.0, 1300.0], $report);
        self::assertStringContainsString('wb_no_rule', json_encode($sheets['Не попадёт в ОПиУ'], \JSON_UNESCAPED_UNICODE) ?: '');
        self::assertContains(['Всего в данных маркетплейсов', 1130.0], $sheets['Сверка']);
    }

    public function testAdsFromStatisticsOnScreenAndInXlsx(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 491);
        $fixture->addWbPromotion(true, '1000.00');
        $fixture->addWbAdStatistics(['wbA' => '600.00', 'wbB' => '300.00'], '50.00');
        $this->em()->clear();
        $this->login($client, $fixture->owner, $fixture->company);

        $crawler = $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="pl-preview-ads-note"]', 'Реклама WB («Продвижение»)');
        self::assertSelectorTextNotContains('[data-testid="pl-preview-ads-note"]', 'Ozon');
        $cells = $crawler->filter('[data-testid="pl-preview-ads"] tr[data-marketplace="wildberries"] td')->each(static fn ($td): string => str_replace("\u{00A0}", ' ', trim($td->text())));
        self::assertSame(['Wildberries', '1 000,00', '950,00', '900,00', '50,00', '50,00'], $cells);

        $client->request('GET', '/marketplace/pl-preview/xlsx?dateFrom=2026-01-01&dateTo=2026-01-31');
        self::assertContains(['Wildberries', 1000.0, 950.0, 900.0, 50.0, 50.0], $this->readXlsx($client->getInternalResponse()->getContent())['Сверка']);
    }

    public function testNoAdsBlockWithoutAdCategories(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 501);
        $this->em()->clear();
        $this->login($client, $fixture->owner, $fixture->company);

        $client->request('GET', self::URL);

        self::assertSelectorNotExists('[data-testid="pl-preview-ads"]');
        self::assertSelectorNotExists('[data-testid="pl-preview-ads-note"]');
    }

    public function testXlsxWithWrongPeriodRedirectsToTheTab(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 481);
        $this->em()->clear();
        $this->login($client, $fixture->owner, $fixture->company);

        $client->request('GET', '/marketplace/pl-preview/xlsx?dateFrom=2026-02-01&dateTo=2026-01-01');

        self::assertResponseRedirects();
        self::assertStringStartsWith('/marketplace/pl-preview?', (string) $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Начало периода позже окончания');
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function readXlsx(string $content): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pl_preview_test_');
        file_put_contents($path, $content);
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);
        $sheets = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    // Целые числа OpenSpout читает как int — приводим к float для сравнения.
                    $rows[] = array_map(static fn (mixed $v): mixed => \is_int($v) ? (float) $v : $v, $row->toArray());
                }
                $sheets[$sheet->getName()] = $rows;
            }
        } finally {
            $reader->close();
            unlink($path);
        }

        return $sheets;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function normalizeSpaces(array $values): array
    {
        return array_map(static fn (string $v): string => str_replace("\u{00A0}", ' ', $v), $values);
    }

    private function login(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
