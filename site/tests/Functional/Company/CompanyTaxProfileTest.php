<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyTaxProfile;
use App\Company\Entity\User;
use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyTaxProfileBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Блок «Налоги» карточки компании: периоды налогового профиля, выбор при создании,
 * строка режима в шапках отчётов.
 */
final class CompanyTaxProfileTest extends WebTestCaseBase
{
    private const COMPANY_ID = '0193bbbb-0000-7000-8000-0000000000c1';

    private KernelBrowser $client;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->owner = UserBuilder::aUser()->withEmail('tax-owner@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withName('Tax Co')->withOwner($this->owner)->build();
        $this->em()->persist($this->owner);
        $this->em()->persist($company);
        $this->em()->flush();
        $this->em()->clear();
        $this->client->loginUser($this->owner);
    }

    public function testOwnerSetsInitialAndDatedPeriods(): void
    {
        $this->saveProfile('USN', 'EXEMPT', '');
        $this->saveProfile('USN', 'USN_5', '2026-03');

        $profiles = $this->profiles();
        self::assertCount(2, $profiles);
        self::assertNull($profiles[0]->getValidFrom());
        self::assertSame(VatMode::EXEMPT, $profiles[0]->getVatMode());
        self::assertSame('2026-03-01', $profiles[1]->getValidFrom()?->format('Y-m-d'));
        self::assertSame(VatMode::USN_5, $profiles[1]->getVatMode());

        $crawler = $this->client->request('GET', sprintf('/company/%s/edit', self::COMPANY_ID));
        $text = $crawler->filter('#taxes')->text();
        self::assertStringContainsString('с начала учёта', $text);
        self::assertStringContainsString('по 02.2026', $text);
        self::assertStringContainsString('с 03.2026', $text);
        self::assertStringContainsString('НДС 5%', $text);
    }

    public function testEditPageGroupsIndependentFormsUnderNamedTabs(): void
    {
        $crawler = $this->client->request('GET', sprintf('/company/%s/edit', self::COMPANY_ID));
        self::assertResponseIsSuccessful();
        $tabs = $crawler->filter('[data-company-edit-tabs] [role="tablist"] [role="tab"]');
        self::assertSame(['Компания', 'Налоги', 'Консолидация'], array_map('trim', $tabs->extract(['_text'])));
        self::assertSame(['company', 'taxes', 'consolidation'], $tabs->extract(['aria-controls']));
        self::assertSame(['true', 'false', 'false'], $tabs->extract(['aria-selected']));

        self::assertSelectorExists('#company[role="tabpanel"].active form[name="company"]');
        self::assertSelectorExists('#company form[action="/finance/reports/account-balances/recalc"]');
        self::assertSelectorExists(sprintf('#taxes[role="tabpanel"] form[action="/company/%s/tax-profile"]', self::COMPANY_ID));
        self::assertSelectorExists(sprintf('#consolidation[role="tabpanel"] form[action="/company/%s/consolidation"]', self::COMPANY_ID));
    }

    public function testInvalidCompanySettingsShowErrorInsideCompanyTab(): void
    {
        $crawler = $this->client->request('GET', sprintf('/company/%s/edit', self::COMPANY_ID));
        $this->client->submit($crawler->selectButton('Сохранить')->form(['company[minimumBalance][amount]' => '-0.01']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#company.active .invalid-feedback', 'Минимальный остаток не может быть отрицательным.');
    }

    public function testSwitchingActiveCompanyOpensItsSettings(): void
    {
        $company = $this->em()->getRepository(Company::class)->find(self::COMPANY_ID);
        self::assertInstanceOf(Company::class, $company);
        $owner = $company->getUser();
        self::assertInstanceOf(User::class, $owner);
        $nextCompany = CompanyBuilder::aCompany()->withIndex(2)->withName('Другая компания')->withOwner($owner)->build();
        $this->em()->persist($nextCompany);
        $this->em()->flush();
        $nextId = (string) $nextCompany->getId();

        $editUrl = sprintf('/company/%s/edit', self::COMPANY_ID);
        $this->setClientSessionValue($this->client, 'active_company_id', self::COMPANY_ID);
        $crawler = $this->client->request('GET', $editUrl);
        $switcher = $crawler->filter('form[action="/company/active"]')->form(['company_id' => $nextId]);
        $this->client->submit($switcher, [], ['HTTP_REFERER' => 'http://localhost'.$editUrl]);

        self::assertResponseRedirects(sprintf('/company/%s/edit', $nextId));
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.vf-page-head h1', 'Другая компания');
        self::assertSelectorExists(sprintf('#taxes form[action="/company/%s/tax-profile"]', $nextId));
        self::assertSelectorExists(sprintf('#consolidation form[action="/company/%s/consolidation"]', $nextId));
        self::assertSame($nextId, $crawler->filter('form[action="/company/active"] option[selected]')->attr('value'));

        $crawler = $this->client->request('GET', '/company/');
        $switcher = $crawler->filter('form[action="/company/active"]')->form(['company_id' => self::COMPANY_ID]);
        $this->client->submit($switcher, [], ['HTTP_REFERER' => 'http://localhost/company/']);
        self::assertResponseRedirects('http://localhost/company/');
    }

    public function testCompanyListShowsDetailsForEachOwnedCompany(): void
    {
        $company = $this->em()->getRepository(Company::class)->find(self::COMPANY_ID);
        self::assertInstanceOf(Company::class, $company);
        $company->setInn('7707083893');
        $owner = $company->getUser();
        self::assertInstanceOf(User::class, $owner);

        $second = CompanyBuilder::aCompany()->withIndex(2)->withName('Другая компания')->withOwner($owner)->build();
        $withoutProfile = CompanyBuilder::aCompany()->withIndex(3)->withName('Без настроек')->withOwner($owner)->build();
        $stranger = UserBuilder::aUser()->withIndex(2)->build();
        $foreign = CompanyBuilder::aCompany()->withIndex(4)->withName('Чужая компания')->withOwner($stranger)->build();
        $this->em()->persist($second);
        $this->em()->persist($withoutProfile);
        $this->em()->persist($stranger);
        $this->em()->persist($foreign);
        $this->em()->flush();

        $this->em()->persist(CompanyTaxProfileBuilder::aProfile()->forCompany(self::COMPANY_ID)->with(TaxRegime::USN, VatMode::USN_5)->build());
        $this->em()->persist(CompanyTaxProfileBuilder::aProfile()->withId('0193bbbb-0000-7000-8000-0000000000a2')->forCompany(self::COMPANY_ID)->with(TaxRegime::USN, VatMode::USN_7)->validFrom('9999-01-01')->build());
        $this->em()->persist(CompanyTaxProfileBuilder::aProfile()->withId('0193bbbb-0000-7000-8000-0000000000a3')->forCompany((string) $second->getId())->with(TaxRegime::OSNO, VatMode::EXEMPT)->build());
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/company/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.vf-page-head a[href="/company/new"]');
        $table = $crawler->filter('#companies-list table');
        self::assertSame(['Наименование', 'ИНН', 'Система налогов', 'НДС'], array_slice($table->filter('thead th')->extract(['_text']), 0, 4));
        self::assertCount(3, $table->filter('tbody tr'));

        foreach ([
            self::COMPANY_ID => ['Tax Co', '7707083893', 'УСН', 'НДС 5%'],
            (string) $second->getId() => ['Другая компания', '—', 'ОСНО', 'без НДС'],
            (string) $withoutProfile->getId() => ['Без настроек', '—', '—', '—'],
        ] as $companyId => $expected) {
            $row = $table->filter(sprintf('tbody a.vf-strong[href="/company/%s/edit"]', $companyId))->closest('tr');
            self::assertNotNull($row);
            self::assertSame($expected, array_slice($row->filter('td')->extract(['_text']), 0, 4));
            self::assertCount(1, $row->filter(sprintf('a[href="/company/%s/delete"]', $companyId)));
        }

        self::assertStringNotContainsString('Чужая компания', $table->text());
    }

    public function testCompanyListPaginatesActiveCompanies(): void
    {
        $company = $this->em()->getRepository(Company::class)->find(self::COMPANY_ID);
        self::assertInstanceOf(Company::class, $company);
        $company->setName('Компания 00');
        $owner = $company->getUser();
        self::assertInstanceOf(User::class, $owner);

        for ($index = 1; $index <= 20; ++$index) {
            $company = CompanyBuilder::aCompany()->withIndex($index)->withName(sprintf('Компания %02d', $index))->withOwner($owner)->build();
            $this->em()->persist($company);
        }
        $this->em()->flush();
        $lastCompanyId = (string) $company->getId();
        $this->em()->persist(CompanyTaxProfileBuilder::aProfile()->forCompany($lastCompanyId)->with(TaxRegime::USN, VatMode::USN_7)->build());
        $this->em()->flush();

        $firstPage = $this->client->request('GET', '/company/');
        self::assertResponseIsSuccessful();
        self::assertCount(20, $firstPage->filter('#companies-list tbody tr'));
        self::assertStringNotContainsString('Компания 20', $firstPage->filter('#companies-list table')->text());
        /** @var array<string, list<array{sql: string, params: array<int, mixed>}>> $queries */
        $queries = self::getContainer()->get('doctrine.debug_data_holder')->getData();
        $taxQueries = array_values(array_filter($queries['default'] ?? [], static fn (array $query): bool => str_contains($query['sql'], 'FROM company_tax_profiles')));
        self::assertCount(1, $taxQueries);
        self::assertNotContains($lastCompanyId, $taxQueries[0]['params']);

        $secondPage = $this->client->click($firstPage->filter('#companies-list a[aria-label="Вперёд"]')->link());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $secondPage->filter('#companies-list tbody tr'));
        self::assertSelectorTextContains('#companies-list tbody tr', 'Компания 20');
        self::assertSelectorTextContains('#companies-list tbody tr', 'НДС 7%');
    }

    public function testRepeatedSaveOfSameMonthChangesSamePeriod(): void
    {
        $this->saveProfile('USN', 'USN_5', '2026-03');
        $firstId = $this->profiles()[0]->getId();

        $this->saveProfile('USN', 'USN_5', '2026-03');
        $this->saveProfile('OSNO', 'GENERAL', '2026-03');

        $profiles = $this->profiles();
        self::assertCount(1, $profiles, 'повтор того же месяца не добавляет период');
        self::assertSame($firstId, $profiles[0]->getId());
        self::assertSame(TaxRegime::OSNO, $profiles[0]->getTaxRegime());
    }

    public function testOwnerSetsTaxRate(): void
    {
        $this->saveProfile('USN', 'EXEMPT', '', '6');
        self::assertSame('6.00', $this->profiles()[0]->getTaxRatePercent());

        $crawler = $this->client->request('GET', sprintf('/company/%s/edit', self::COMPANY_ID));
        self::assertStringContainsString('6%', $crawler->filter('#taxes table')->text());

        // Ставка вне 0…100 отклоняется формой, период не меняется.
        $this->saveProfile('USN', 'EXEMPT', '', '150');
        $this->client->followRedirect();
        self::assertStringContainsString('Ставка налога — число от 0 до 100.', (string) $this->client->getResponse()->getContent());
        self::assertSame('6.00', $this->profiles()[0]->getTaxRatePercent());

        // Пустая ставка — «не задана».
        $this->saveProfile('USN', 'EXEMPT', '');
        self::assertNull($this->profiles()[0]->getTaxRatePercent());
    }

    public function testDisallowedCombinationIsRejected(): void
    {
        $crawler = $this->client->request('GET', sprintf('/company/%s/edit', self::COMPANY_ID));
        $form = $crawler->selectButton('Сохранить режим')->form();
        $form->disableValidation()->setValues([
            'company_tax_profile[taxRegime]' => 'PSN',
            'company_tax_profile[vatMode]' => 'GENERAL',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects(sprintf('/company/%s/edit#taxes', self::COMPANY_ID));
        $this->client->followRedirect();
        self::assertStringContainsString('Режим «Патент» не допускает «НДС по общим ставкам».', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->profiles());
    }

    public function testMonthInWrongFormatIsRejected(): void
    {
        $this->saveProfile('USN', 'EXEMPT', '2026-13');

        $this->client->followRedirect();
        self::assertStringContainsString('Укажите месяц в формате ГГГГ-ММ.', (string) $this->client->getResponse()->getContent());

        // Год 0000 PHP разбирает, а PostgreSQL отвергает — должен отсекаться формой, а не 500.
        $this->saveProfile('USN', 'EXEMPT', '0000-01');
        self::assertSame([], $this->profiles());
    }

    public function testForeignCompanyIsNotFound(): void
    {
        $stranger = UserBuilder::aUser()->withIndex(2)->withEmail('tax-stranger@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $this->em()->persist($stranger);
        // Своя компания у чужого пользователя — чтобы до контроллера доходил запрос, а не отсекал модульный гейт.
        $this->em()->persist(CompanyBuilder::aCompany()->withIndex(3)->withOwner($stranger)->build());
        $profile = CompanyTaxProfileBuilder::aProfile()->forCompany(self::COMPANY_ID)->build();
        $this->em()->persist($profile);
        $this->em()->flush();
        $this->client->loginUser($stranger);

        $this->client->request('POST', sprintf('/company/%s/tax-profile', self::COMPANY_ID), [
            'company_tax_profile' => ['taxRegime' => 'OSNO', 'vatMode' => 'GENERAL', 'validFrom' => ''],
        ]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', sprintf('/company/%s/tax-profile/%s/delete', self::COMPANY_ID, CompanyTaxProfileBuilder::DEFAULT_ID));
        self::assertResponseStatusCodeSame(404);

        self::assertSame(VatMode::EXEMPT, $this->profiles()[0]->getVatMode());
    }

    public function testOwnerDeletesPeriod(): void
    {
        $this->saveProfile('USN', 'EXEMPT', '');
        $profileId = $this->profiles()[0]->getId();

        $crawler = $this->client->request('GET', sprintf('/company/%s/edit', self::COMPANY_ID));
        $this->submitConfirmTrigger($this->client, $crawler->filter('#taxes [data-confirm-action]'));

        self::assertResponseRedirects(sprintf('/company/%s/edit#taxes', self::COMPANY_ID));
        self::assertSame([], $this->profiles());

        // Чужой или уже удалённый период — 404, а не 500.
        $this->client->request('POST', sprintf('/company/%s/tax-profile/%s/delete', self::COMPANY_ID, $profileId), [
            '_token' => $this->csrfToken($this->client, 'delete_tax_profile'.$profileId),
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testNewCompanyStoresInitialProfileOrNone(): void
    {
        $crawler = $this->client->request('GET', '/company/new');
        self::assertCount(1, $crawler->filter('[data-tax-profile-fields][data-matrix] [data-tax-regime]'));
        self::assertCount(1, $crawler->filter('[data-tax-profile-fields][data-matrix] [data-vat-mode]'));
        $this->client->submit($crawler->selectButton('Создать')->form([
            'company[name]' => 'New Tax Co',
            'company[taxProfile][taxRegime]' => 'USN',
            'company[taxProfile][vatMode]' => 'USN_7',
        ]));
        self::assertResponseRedirects('/company/');

        $profiles = $this->profiles($this->companyNamed('New Tax Co'));
        self::assertCount(1, $profiles);
        self::assertNull($profiles[0]->getValidFrom());
        self::assertSame(VatMode::USN_7, $profiles[0]->getVatMode());

        $crawler = $this->client->request('GET', '/company/new');
        $this->client->submit($crawler->selectButton('Создать')->form(['company[name]' => 'Later Tax Co']));
        self::assertResponseRedirects('/company/');
        self::assertSame([], $this->profiles($this->companyNamed('Later Tax Co')));
    }

    public function testNewCompanyRejectsHalfFilledProfile(): void
    {
        $crawler = $this->client->request('GET', '/company/new');
        $this->client->submit($crawler->selectButton('Создать')->form([
            'company[name]' => 'Half Tax Co',
            'company[taxProfile][taxRegime]' => 'USN',
        ]));

        self::assertFalse($this->client->getResponse()->isRedirect());
        self::assertStringContainsString('Укажите и режим налогообложения, и режим НДС', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->em()->getRepository(Company::class)->findOneBy(['name' => 'Half Tax Co']));
    }

    public function testReportHeadersShowTaxProfileOrWarning(): void
    {
        $this->setClientSessionValue($this->client, 'active_company_id', self::COMPANY_ID);

        $this->client->request('GET', '/finance/reports/cashflow');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#cashflow-filter-form[data-report-filters]');
        self::assertSelectorTextContains('[data-tax-profile-line]', 'Налоговый режим не выбран');

        $this->em()->persist(CompanyTaxProfileBuilder::aProfile()->forCompany(self::COMPANY_ID)->with(TaxRegime::USN, VatMode::EXEMPT)->build());
        $this->em()->flush();

        $this->client->request('GET', '/finance/reports/cashflow');
        self::assertSelectorTextContains('[data-tax-profile-line]', 'Налоговый режим: УСН, без НДС');

        $this->client->request('GET', '/finance/report/preview');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-tax-profile-line]', 'Налоговый режим: УСН, без НДС');
    }

    private function saveProfile(string $regime, string $vatMode, string $validFrom, string $taxRate = ''): void
    {
        $crawler = $this->client->request('GET', sprintf('/company/%s/edit', self::COMPANY_ID));
        $form = $crawler->selectButton('Сохранить режим')->form();
        $form->disableValidation()->setValues([
            'company_tax_profile[taxRegime]' => $regime,
            'company_tax_profile[vatMode]' => $vatMode,
            'company_tax_profile[validFrom]' => $validFrom,
            'company_tax_profile[taxRatePercent]' => $taxRate,
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects(sprintf('/company/%s/edit#taxes', self::COMPANY_ID));
    }

    /**
     * @return list<CompanyTaxProfile>
     */
    private function profiles(?Company $company = null): array
    {
        $this->em()->clear();

        /** @var list<CompanyTaxProfile> $profiles */
        $profiles = $this->em()->createQueryBuilder()
            ->select('p')
            ->from(CompanyTaxProfile::class, 'p')
            ->where('p.companyId = :companyId')
            ->setParameter('companyId', null === $company ? self::COMPANY_ID : (string) $company->getId())
            ->orderBy('p.validFrom', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        // Начальный (NULL) — первым, как в карточке.
        usort($profiles, static fn (CompanyTaxProfile $a, CompanyTaxProfile $b): int => [null !== $a->getValidFrom(), $a->getValidFrom()] <=> [null !== $b->getValidFrom(), $b->getValidFrom()]);

        return $profiles;
    }

    private function companyNamed(string $name): Company
    {
        $company = $this->em()->getRepository(Company::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }
}
