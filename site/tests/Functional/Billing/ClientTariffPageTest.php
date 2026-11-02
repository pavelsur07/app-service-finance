<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Billing\Entity\Tariff;
use App\Billing\Enum\TariffCode;
use App\Billing\Repository\TariffRepository;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Tests\Builders\Billing\BillingPaymentBuilder;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Страница «Тариф и оплата» клиента и баннер об окончании оплаты. «Сегодня» — 10.03.2027 по Москве.
 */
final class ClientTariffPageTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(ClockInterface::class, new MockClock(new \DateTimeImmutable('2027-03-10 10:00:00', new \DateTimeZone('Europe/Moscow'))));

        $this->owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('tariff-owner@example.test')->build();
        $this->company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($this->owner)->build();
        $this->em()->persist($this->owner);
        $this->em()->persist($this->company);
        $this->em()->flush();
    }

    public function testOwnerSeesTariffPricesHowToPayAndOwnPaymentsOnly(): void
    {
        $start = $this->tariff(TariffCode::START);
        $this->em()->persist(CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId())->onTariff($start)->paid('2027-02-20', '2027-05-19')->build());
        $this->em()->persist(BillingPaymentBuilder::aPayment()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId())->onTariff($start)->withExternalId('СЧ-777')->build());
        // Чужая оплата не должна попасть в историю.
        $other = $this->otherCompany();
        $this->em()->persist(BillingPaymentBuilder::aPayment()->withId(Uuid::uuid7()->toString())->forCompany($other)->onTariff($start)->withExternalId('ЧУЖОЙ-1')->build());
        $this->em()->flush();
        $this->em()->clear();

        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/company/price');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-billing-current]', 'Старт');
        self::assertSelectorTextContains('[data-billing-current]', '19.05.2027');
        $prices = $crawler->filter('[data-billing-prices]')->text();
        foreach (['Старт', 'База', 'Профи'] as $name) {
            self::assertStringContainsString($name, $prices);
        }
        self::assertStringNotContainsString('Друг', $prices);
        self::assertStringNotContainsString('Аутсорсер', $prices);
        self::assertMatchesRegularExpression('/−20%/u', $prices);
        self::assertSelectorTextContains('[data-billing-how-to-pay]', 'support@vashfindir.ru');
        self::assertSelectorTextContains('[data-billing-payments]', 'СЧ-777');
        self::assertStringNotContainsString('ЧУЖОЙ-1', (string) $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('[data-billing-notice]', '70 дней до конца — без баннера');
    }

    public function testMemberSeesTariffButNotPayments(): void
    {
        $this->em()->persist(CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId())->onTariff($this->tariff(TariffCode::FRIEND))->perpetualFrom('2026-11-01')->build());
        $memberUser = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('tariff-member@example.test')->build();
        $this->em()->persist($memberUser);
        $this->em()->persist(CompanyMemberBuilder::aMember()->withCompany($this->company)->withUser($memberUser)->withRole(CompanyMember::ROLE_OPERATOR)->build());
        $this->em()->flush();

        $this->login($memberUser);
        $this->client->request('GET', '/company/price');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-billing-current]', 'Друг');
        self::assertSelectorTextContains('[data-billing-current]', 'бессрочно');
        self::assertSelectorNotExists('[data-billing-payments]');
        self::assertSelectorNotExists('[data-billing-notice]');
    }

    public function testTrialEndingShowsBannerInBothLayouts(): void
    {
        $this->em()->persist(CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId())->onTariff($this->tariff(TariffCode::BASE))->trial('2027-02-28', '2027-03-13')->build());
        $this->em()->flush();
        $this->login($this->owner);

        $this->client->request('GET', '/company/price');
        self::assertSelectorExists('[data-billing-notice="trial_ending"]');
        self::assertSelectorTextContains('[data-billing-notice]', '13.03.2027');
        self::assertSelectorTextContains('[data-billing-current]', 'пробный период');

        $this->client->request('GET', '/settings/api'); // страница на новом layout
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.app-workzone [data-billing-notice="trial_ending"]');
    }

    public function testGraceShowsWarningWithReadOnlyDate(): void
    {
        $this->em()->persist(CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId())->onTariff($this->tariff(TariffCode::START))->paid('2027-02-08', '2027-03-07')->build());
        $this->em()->flush();
        $this->login($this->owner);

        $this->client->request('GET', '/company/price');

        self::assertSelectorExists('[data-billing-notice="grace"]');
        self::assertSelectorTextContains('[data-billing-notice]', 'До 12.03.2027 всё работает');
    }

    public function testOutOfRangeHistoryPageIsNotFound(): void
    {
        $this->login($this->owner);

        $this->client->request('GET', '/company/price?page=2');

        self::assertResponseStatusCodeSame(404);
    }

    private function login(User $user): void
    {
        $this->client->loginUser($user);
        $this->setClientSessionValue($this->client, 'active_company_id', $this->company->getId());
    }

    private function companyId(): string
    {
        return (string) $this->company->getId();
    }

    private function otherCompany(): string
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('tariff-other@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);

        return (string) $company->getId();
    }

    private function tariff(TariffCode $code): Tariff
    {
        $tariff = static::getContainer()->get(TariffRepository::class)->findOneByCode($code);
        self::assertNotNull($tariff);

        return $tariff;
    }
}
