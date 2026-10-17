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
    }

    public function testOtherCompanySeesNothingOfThisOne(): void
    {
        $client = static::createClient();
        new PlPreviewFixture($this->em(), 441);
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
