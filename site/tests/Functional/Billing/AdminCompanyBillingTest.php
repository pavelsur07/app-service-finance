<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Billing\Entity\CompanySubscription;
use App\Billing\Enum\TariffCode;
use App\Billing\Repository\TariffRepository;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Billing\BillingCheckoutBuilder;
use App\Tests\Builders\Billing\BillingPaymentBuilder;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Billing\YooKassaResponses;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

final class AdminCompanyBillingTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private User $admin;
    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->admin = UserBuilder::aUser()->withIndex(1)->withEmail('admin+billing@example.test')->withRoles(['ROLE_ADMIN'])->build();
        $this->owner = UserBuilder::aUser()->withIndex(2)->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withName('ООО Тарифный')->withOwner($this->owner)->build();

        $em = $this->em();
        foreach ([$this->admin, $this->owner, $this->company] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();
    }

    public function testAdminSeesCompanyWithoutTariff(): void
    {
        $this->client->loginUser($this->admin, 'admin');
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('ООО Тарифный', $content);
        self::assertStringContainsString('Нет тарифа', $content);
        self::assertStringContainsString('Оплат пока нет', $content);
        self::assertMatchesRegularExpression('/>47\s710</u', $content, 'price list hint: Старт, 12 мес (разделитель — неразрывный пробел)');
    }

    public function testNonAdminHasNoAccess(): void
    {
        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->url());

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownCompanyIsNotFound(): void
    {
        $this->client->loginUser($this->admin, 'admin');
        $this->client->request('GET', '/admin/billing/companies/0193ffff-0000-7000-8000-000000000999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAssignSupportTariffWithContractDates(): void
    {
        $this->submit('Назначить', ['tariff' => 'support', 'from' => '2026-11-01', 'until' => '2027-10-31']);

        $subscription = $this->subscription();
        self::assertSame(TariffCode::SUPPORT, $subscription->getTariff()->getCode());
        self::assertSame('2026-11-01', $subscription->getPaidFrom()->format('Y-m-d'));
        self::assertSame('2027-10-31', $subscription->getPaidUntil()?->format('Y-m-d'));
        self::assertStringContainsString('Тариф назначен', $this->followedContent());
    }

    public function testAssignWithReversedDatesShowsErrorAndChangesNothing(): void
    {
        $this->submit('Назначить', ['tariff' => 'outsourcer', 'from' => '2027-02-01', 'until' => '2027-01-01']);

        self::assertStringContainsString('Дата начала позже даты окончания', $this->followedContent());
        self::assertNull($this->em()->getRepository(CompanySubscription::class)->findOneBy(['companyId' => $this->company->getId()]));
    }

    public function testPaymentRegistersPeriodAndRepeatedSubmitDoesNotDuplicate(): void
    {
        $values = ['tariff' => 'base', 'months' => '3', 'amount' => '28 360', 'received_on' => '2027-01-15', 'invoice' => 'СЧ-15'];
        $crawler = $this->openPage();
        $form = $crawler->selectButton('Зачислить оплату')->form($values);

        $this->client->submit($form);
        self::assertResponseRedirects($this->url());
        $this->client->submit($form); // повторная отправка той же формы — тот же payment_id

        self::assertSame(1, $this->paymentCount());
        self::assertSame(
            ['amount_kopecks' => 2836000, 'months' => 3, 'period_from' => '2027-01-15', 'period_until' => '2027-04-14', 'external_id' => 'СЧ-15'],
            $this->em()->getConnection()->fetchAssociative('SELECT amount_kopecks, months, period_from, period_until, external_id FROM billing_payments WHERE company_id = ?', [$this->company->getId()]),
        );
        self::assertStringContainsString('с 15.01.2027 по 14.04.2027', $this->followedContent());
    }

    public function testPaymentOnFriendRequiresConfirmation(): void
    {
        $friend = static::getContainer()->get(TariffRepository::class)->findOneByCode(TariffCode::FRIEND);
        self::assertNotNull($friend);
        $this->em()->persist(CompanySubscriptionBuilder::aSubscription()->forCompany((string) $this->company->getId())->onTariff($friend)->perpetualFrom('2026-11-01')->build());
        $this->em()->flush();
        $this->em()->clear();

        $this->submit('Зачислить оплату', ['tariff' => 'start', 'months' => '1', 'amount' => '4970', 'received_on' => '2027-01-15']);
        self::assertStringContainsString('подтвердите перевод на платный', $this->followedContent());
        self::assertSame(0, $this->paymentCount());

        $this->submit('Зачислить оплату', ['tariff' => 'start', 'months' => '1', 'amount' => '4970', 'received_on' => '2027-01-15', 'confirm_leave_perpetual' => true]);
        self::assertSame(1, $this->paymentCount());
        self::assertSame(TariffCode::START, $this->subscription()->getTariff()->getCode());
    }

    public function testCheaperTariffDuringActivePeriodIsScheduledAndShown(): void
    {
        $pro = static::getContainer()->get(TariffRepository::class)->findOneByCode(TariffCode::PRO);
        self::assertNotNull($pro);
        $this->em()->persist(CompanySubscriptionBuilder::aSubscription()->forCompany((string) $this->company->getId())->onTariff($pro)->paid('2027-01-01', '2099-01-31')->build());
        $this->em()->flush();
        $this->em()->clear();

        $this->submit('Зачислить оплату', ['tariff' => 'start', 'months' => '1', 'amount' => '4970', 'received_on' => '2027-01-15']);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-billing-next-tariff]', 'С 01.02.2099 — тариф «Старт»');
        self::assertSame(TariffCode::PRO, $this->subscription()->getTariff()->getCode());
        self::assertSame('2099-02-28', $this->subscription()->getPaidUntil()?->format('Y-m-d'));
    }

    public function testScheduledSwitchCanBeCancelled(): void
    {
        $this->testCheaperTariffDuringActivePeriodIsScheduledAndShown();

        $crawler = $this->openPage();
        $form = $crawler->filter('[data-billing-cancel-next]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects($this->url());

        $content = $this->followedContent();
        self::assertStringContainsString('Запланированный переход отменён', $content);
        self::assertStringNotContainsString('data-billing-next-tariff', $content);
        self::assertNull($this->subscription()->getNextTariff());
    }

    public function testCancelWithoutCsrfIsForbidden(): void
    {
        $this->client->loginUser($this->admin, 'admin');
        $this->client->request('POST', $this->url().'/next-tariff/cancel', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testShorteningPaidPeriodNeedsCheckbox(): void
    {
        $this->submit('Зачислить оплату', ['tariff' => 'start', 'months' => '1', 'amount' => '4970', 'received_on' => '2027-01-15']);

        $this->submit('Назначить', ['tariff' => 'start', 'from' => '2027-01-15', 'until' => '2027-01-31']);
        self::assertStringContainsString('отметьте «Сократить оплаченный период»', $this->followedContent());
        self::assertSame('2027-02-14', $this->subscription()->getPaidUntil()?->format('Y-m-d'));

        $this->submit('Назначить', ['tariff' => 'start', 'from' => '2027-01-15', 'until' => '2027-01-31', 'confirm_shorten' => true]);
        self::assertSame('2027-01-31', $this->subscription()->getPaidUntil()?->format('Y-m-d'));
    }

    public function testHiddenTariffDuringActivePeriodShowsError(): void
    {
        $start = static::getContainer()->get(TariffRepository::class)->findOneByCode(TariffCode::START);
        self::assertNotNull($start);
        $this->em()->persist(CompanySubscriptionBuilder::aSubscription()->forCompany((string) $this->company->getId())->onTariff($start)->paid('2027-01-01', '2099-01-31')->build());
        $this->em()->flush();
        $this->em()->clear();

        $this->submit('Зачислить оплату', ['tariff' => 'support', 'months' => '12', 'amount' => '100000', 'received_on' => '2027-01-15']);

        self::assertStringContainsString('можно только через администратора', $this->followedContent());
        self::assertSame(0, $this->paymentCount());
    }

    public function testTooLargeAmountShowsErrorInsteadOfServerError(): void
    {
        $this->submit('Зачислить оплату', ['tariff' => 'start', 'months' => '1', 'amount' => '99999999', 'received_on' => '2027-01-15']);

        self::assertStringContainsString('Сумма оплаты — от 0 до', $this->followedContent());
        self::assertSame(0, $this->paymentCount());
    }

    public function testUsersPageShowsTariffBadgeWithLink(): void
    {
        $base = static::getContainer()->get(TariffRepository::class)->findOneByCode(TariffCode::BASE);
        self::assertNotNull($base);
        $this->em()->persist(CompanySubscriptionBuilder::aSubscription()->forCompany((string) $this->company->getId())->onTariff($base)->paid('2026-10-15', '2099-11-14')->build());
        $this->em()->flush();
        $this->em()->clear();

        $this->client->loginUser($this->admin, 'admin');
        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        $badge = $crawler->filter(sprintf('[data-billing-badge="%s"]', $this->company->getId()));
        self::assertCount(1, $badge);
        self::assertStringContainsString('База · по 14.11.2099', trim($badge->text()));
        self::assertSame($this->url(), $badge->attr('href'));
    }

    public function testFailedOnlinePaymentIsListedUntilRegisteredManually(): void
    {
        $tariff = static::getContainer()->get(TariffRepository::class)->findOneByCode(TariffCode::START);
        self::assertNotNull($tariff);
        $checkout = BillingCheckoutBuilder::aCheckout()->forCompany((string) $this->company->getId())->onTariff($tariff, 1, 497000)->build();
        $this->em()->persist($checkout);
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement("UPDATE billing_checkouts SET status = 'failed_to_apply', failure_reason = 'ЮKassa: 100 RUB, ожидалось 497000 RUB' WHERE id = ?", [$checkout->getId()]);
        $this->em()->clear();

        $crawler = $this->openPage();
        self::assertStringContainsString(YooKassaResponses::PAYMENT_ID, $crawler->filter('[data-billing-failed-checkouts]')->text());
        self::assertStringContainsString('ожидалось 497000 RUB', $crawler->filter('[data-billing-failed-checkouts]')->text());

        $tariff = static::getContainer()->get(TariffRepository::class)->findOneByCode(TariffCode::START);
        self::assertNotNull($tariff);
        $this->em()->persist(BillingPaymentBuilder::aPayment()->withId(Uuid::uuid7()->toString())->forCompany((string) $this->company->getId())->onTariff($tariff)->withExternalId(YooKassaResponses::PAYMENT_ID)->build());
        $this->em()->flush();

        $crawler = $this->openPage();
        self::assertCount(0, $crawler->filter('[data-billing-failed-checkouts]'), 'отмеченная вручную оплата из списка уходит');

        // Платёж больше суток без конечного статуса — тоже на виду у администратора.
        $tariff = static::getContainer()->get(TariffRepository::class)->findOneByCode(TariffCode::START);
        self::assertNotNull($tariff);
        $stale = BillingCheckoutBuilder::aCheckout()->withId(Uuid::uuid7()->toString())->forCompany((string) $this->company->getId())->onTariff($tariff, 1, 497000)
            ->withProviderPayment('2f8a6b3c-000f-5000-9000-000000000077')->createdAt('2026-01-01 10:00:00')->build();
        $this->em()->persist($stale);
        $this->em()->flush();
        $crawler = $this->openPage();
        self::assertStringContainsString('нет конечного статуса больше суток', $crawler->filter('[data-billing-failed-checkouts]')->text());
        self::assertStringContainsString('Счёт', $crawler->filter('table')->last()->text());
    }

    private function url(): string
    {
        return '/admin/billing/companies/'.$this->company->getId();
    }

    private function openPage(): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->loginUser($this->admin, 'admin');
        $crawler = $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @param array<string, string|bool> $values
     */
    private function submit(string $button, array $values): void
    {
        $form = $this->openPage()->selectButton($button)->form();
        foreach ($values as $field => $value) {
            if (true === $value) {
                $checkbox = $form[$field];
                self::assertInstanceOf(ChoiceFormField::class, $checkbox);
                $checkbox->tick();
            } else {
                $form[$field] = $value; // @phpstan-ignore offsetAssign.valueType
            }
        }
        $this->client->submit($form);
        self::assertResponseRedirects($this->url());
    }

    private function followedContent(): string
    {
        $this->client->followRedirect();

        return (string) $this->client->getResponse()->getContent();
    }

    private function subscription(): CompanySubscription
    {
        $this->em()->clear();
        $subscription = $this->em()->getRepository(CompanySubscription::class)->findOneBy(['companyId' => $this->company->getId()]);
        self::assertNotNull($subscription);

        return $subscription;
    }

    private function paymentCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM billing_payments WHERE company_id = ?', [$this->company->getId()]);
    }
}
