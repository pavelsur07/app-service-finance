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
        $this->client->submit($crawler->filter('#taxes')->selectButton('Удалить')->form());

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
        self::assertSelectorTextContains('[data-tax-profile-line]', 'Налоговый режим не выбран');

        $this->em()->persist(CompanyTaxProfileBuilder::aProfile()->forCompany(self::COMPANY_ID)->with(TaxRegime::USN, VatMode::EXEMPT)->build());
        $this->em()->flush();

        $this->client->request('GET', '/finance/reports/cashflow');
        self::assertSelectorTextContains('[data-tax-profile-line]', 'Налоговый режим: УСН, без НДС');

        $this->client->request('GET', '/finance/report/preview');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-tax-profile-line]', 'Налоговый режим: УСН, без НДС');
    }

    private function saveProfile(string $regime, string $vatMode, string $validFrom): void
    {
        $crawler = $this->client->request('GET', sprintf('/company/%s/edit', self::COMPANY_ID));
        $form = $crawler->selectButton('Сохранить режим')->form();
        $form->disableValidation()->setValues([
            'company_tax_profile[taxRegime]' => $regime,
            'company_tax_profile[vatMode]' => $vatMode,
            'company_tax_profile[validFrom]' => $validFrom,
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
