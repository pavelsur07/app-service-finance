<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Billing\Entity\Tariff;
use App\Billing\Enum\TariffCode;
use App\Billing\Infrastructure\YooKassa\YooKassaConfig;
use App\Billing\Message\ApplyYooKassaPaymentMessage;
use App\Billing\Repository\TariffRepository;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Tests\Builders\Billing\BillingCheckoutBuilder;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Billing\YooKassaResponses;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Онлайн-оплата на странице «Тариф и оплата» и уведомления ЮKassa (мок-ответы API v3).
 * «Сегодня» — 10.03.2027 по Москве.
 */
final class OnlinePaymentTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private Company $company;
    private User $owner;
    private YooKassaResponses $yooKassa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(ClockInterface::class, new MockClock(new \DateTimeImmutable('2027-03-10 10:00:00', new \DateTimeZone('Europe/Moscow'))));
        $this->yooKassa = YooKassaResponses::install(static::getContainer()->get('billing.yookassa.http_client'));

        $this->owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('online-owner@example.test')->build();
        $this->company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($this->owner)->build();
        $this->em()->persist($this->owner);
        $this->em()->persist($this->company);
        $this->em()->flush();
    }

    public function testWebhookQueuesCheckWithoutLoginAndRejectsGarbage(): void
    {
        $this->client->request('POST', '/billing/yookassa/webhook', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'type' => 'notification',
            'event' => 'payment.succeeded',
            'object' => ['id' => YooKassaResponses::PAYMENT_ID, 'status' => 'succeeded', 'paid' => true, 'amount' => ['value' => '4970.00', 'currency' => 'RUB']],
        ]));
        self::assertResponseStatusCodeSame(200);

        $sent = $this->syncTransport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ApplyYooKassaPaymentMessage::class, $message);
        self::assertSame(YooKassaResponses::PAYMENT_ID, $message->providerPaymentId);
        self::assertSame([], $this->yooKassa->requests, 'уведомление само в ЮKassa не ходит — только очередь');

        $this->client->request('POST', '/billing/yookassa/webhook', [], [], ['CONTENT_TYPE' => 'application/json'], '{"event":"payment.succeeded","object":{"id":"../../x"}}');
        self::assertResponseStatusCodeSame(400);
        // In-memory транспорт сбрасывается в начале каждого запроса: пусто — значит, этот запрос ничего не поставил.
        self::assertSame([], $this->syncTransport()->getSent());
    }

    public function testOwnerPaysOnlineAndReturnShowsSuccessAndHistory(): void
    {
        $this->subscribe(CompanySubscriptionBuilder::aSubscription()->onTariff($this->tariff(TariffCode::BASE))->trial('2027-03-01', '2027-03-14'));
        $this->login($this->owner);

        $crawler = $this->client->request('GET', '/company/price');
        self::assertSelectorExists('[data-billing-online]');
        $form = $crawler->selectButton('Оплатить онлайн')->form();
        $checkoutId = (string) $crawler->filter('[data-billing-online] input[name="checkout_id"]')->attr('value');
        $form['tariff'] = 'base';
        $form['months'] = '3';
        $this->yooKassa->then(YooKassaResponses::payment('pending', 2836000, $this->metadata($checkoutId)));

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
        self::assertStringStartsWith('https://yoomoney.ru/checkout/', (string) $this->client->getResponse()->headers->get('Location'));
        $body = json_decode((string) $this->yooKassa->requests[0]['options']['body'], true);
        self::assertIsArray($body);
        self::assertSame('http://localhost/company/price?checkout='.$checkoutId, $body['confirmation']['return_url']);

        // Клиент оплатил и вернулся раньше уведомления.
        $this->yooKassa->then(YooKassaResponses::payment('succeeded', 2836000, $this->metadata($checkoutId)));
        $this->client->request('GET', '/company/price?checkout='.$checkoutId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-billing-checkout-status="succeeded"]');
        self::assertSelectorTextContains('[data-billing-current]', '14.06.2027', 'пробный до 14.03 + 3 месяца');
        self::assertSelectorTextContains('[data-billing-payments]', 'ЮKassa');
        self::assertSelectorTextContains('[data-billing-payments]', YooKassaResponses::PAYMENT_ID);

        // Повторное открытие ссылки возврата ничего не делает.
        $this->client->request('GET', '/company/price?checkout='.$checkoutId);
        self::assertSelectorExists('[data-billing-checkout-status="succeeded"]');
        self::assertCount(2, $this->yooKassa->requests);
    }

    public function testReturnWhileYooKassaIsDownShowsProcessing(): void
    {
        $checkout = BillingCheckoutBuilder::aCheckout()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId())->onTariff($this->tariff(TariffCode::START), 1, 497000)->build();
        $this->em()->persist($checkout);
        $this->em()->flush();
        $this->login($this->owner);
        $this->yooKassa->then(YooKassaResponses::error(503));

        $this->client->request('GET', '/company/price?checkout='.$checkout->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-billing-checkout-status="pending"]');
    }

    public function testNoOnlineButtonForFriendMemberAndWithoutKeys(): void
    {
        $this->subscribe(CompanySubscriptionBuilder::aSubscription()->onTariff($this->tariff(TariffCode::FRIEND))->perpetualFrom('2026-11-01'));
        $this->login($this->owner);
        $this->client->request('GET', '/company/price');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-billing-online]', '«Друг» — без кнопки');

        $member = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('online-member@example.test')->build();
        $this->em()->persist($member);
        $this->em()->persist(CompanyMemberBuilder::aMember()->withCompany($this->company)->withUser($member)->withRole(CompanyMember::ROLE_OPERATOR)->build());
        $this->em()->flush();
        $this->login($member);
        $this->client->request('POST', '/company/price/checkout', ['checkout_id' => Uuid::uuid7()->toString(), 'tariff' => 'start', 'months' => 1]);
        self::assertResponseStatusCodeSame(403, 'участник не оплачивает');
        self::assertSame([], $this->yooKassa->requests);
    }

    public function testNoOnlineButtonWithoutKeys(): void
    {
        static::getContainer()->set(YooKassaConfig::class, new YooKassaConfig('', ''));
        $this->login($this->owner);

        $this->client->request('GET', '/company/price');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-billing-online]');
        self::assertSelectorTextContains('[data-billing-how-to-pay]', 'Как оплатить');
    }

    public function testPaidPeriodOffersOnlyCurrentTariff(): void
    {
        $this->subscribe(CompanySubscriptionBuilder::aSubscription()->onTariff($this->tariff(TariffCode::START))->paid('2027-03-01', '2027-03-31'));
        $this->login($this->owner);

        $crawler = $this->client->request('GET', '/company/price');

        self::assertSame(['start'], $crawler->filter('#billing-online-tariff option')->each(static fn ($o): string => (string) $o->attr('value')));
        self::assertSelectorTextContains('[data-billing-online]', 'только текущий тариф');
    }

    public function testCheckoutWorksInReadOnlyMode(): void
    {
        // Оплачено по 01.02, льготные до 06.02 — 10.03 «только чтение» (флаг в .env.test включён).
        $this->subscribe(CompanySubscriptionBuilder::aSubscription()->onTariff($this->tariff(TariffCode::START))->paid('2027-01-02', '2027-02-01'));
        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/company/price');
        $form = $crawler->selectButton('Оплатить онлайн')->form();
        $this->yooKassa->then(YooKassaResponses::payment('pending', 497000, $this->metadata((string) $crawler->filter('[data-billing-online] input[name="checkout_id"]')->attr('value'))));

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
        self::assertStringStartsWith('https://yoomoney.ru/', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function subscribe(CompanySubscriptionBuilder $builder): void
    {
        $this->em()->persist($builder->withId(Uuid::uuid7()->toString())->forCompany($this->companyId())->build());
        $this->em()->flush();
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

    /**
     * @return array<string, string>
     */
    private function metadata(string $checkoutId): array
    {
        return ['checkout_id' => $checkoutId, 'company_id' => $this->companyId()];
    }

    private function syncTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async_sync');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function tariff(TariffCode $code): Tariff
    {
        $tariff = static::getContainer()->get(TariffRepository::class)->findOneByCode($code);
        self::assertNotNull($tariff);

        return $tariff;
    }
}
