<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Billing\Application\DTO\RegisterPaymentCommand;
use App\Billing\Application\RegisterPaymentAction;
use App\Billing\Entity\Tariff;
use App\Billing\Enum\PaymentSource;
use App\Billing\Enum\TariffCode;
use App\Billing\Facade\BillingFacadeInterface;
use App\Billing\Infrastructure\Http\ReadOnlyModeSubscriber;
use App\Billing\Repository\TariffRepository;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Режим «только чтение» (этап 4). В .env.test флаг включён. «Сегодня» — 10.03.2027 по Москве:
 * оплачено по 01.02 → льготные дни до 06.02 → только чтение.
 */
final class ReadOnlyModeTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private Company $company;
    private User $owner;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clock = new MockClock(new \DateTimeImmutable('2027-03-10 10:00:00', new \DateTimeZone('Europe/Moscow')));
        static::getContainer()->set(ClockInterface::class, $this->clock);

        $this->owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('read-only-owner@example.test')->build();
        $this->company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($this->owner)->build();
        $this->em()->persist($this->owner);
        $this->em()->persist($this->company);
        $this->em()->flush();
    }

    public function testReadOnlyBlocksFormPostAndJsonButKeepsPages(): void
    {
        $this->subscribe(TariffCode::START, '2027-01-02', '2027-02-01');
        $this->login();

        $this->client->request('POST', $this->editUrl(), ['company' => ['name' => 'Новое имя']]);
        self::assertResponseRedirects('/company/price', 303);
        self::assertSame(CompanyBuilder::DEFAULT_COMPANY_NAME, $this->companyName(), 'данные не изменились');

        $this->client->request('POST', $this->editUrl(), [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('billing_read_only', json_decode((string) $this->client->getResponse()->getContent(), true)['error']['code'] ?? null);

        $this->client->request('GET', '/company/price');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-billing-notice="read_only"]', 'только чтение');
        self::assertSelectorTextContains('body', 'Продлите тариф, чтобы вносить изменения');

        $this->client->request('GET', $this->editUrl());
        self::assertResponseIsSuccessful();
    }

    public function testAllowedRoutesPassInReadOnly(): void
    {
        $this->subscribe(TariffCode::START, '2027-01-02', '2027-02-01');
        $this->login();

        $this->client->request('POST', '/settings/ui-mode', ['mode' => 'legacy']);
        self::assertFalse($this->redirectedToTariff());

        $this->client->request('POST', '/company/active', ['company_id' => $this->company->getId()]);
        self::assertFalse($this->redirectedToTariff());
    }

    public function testGraceFriendAndNoTariffAreNotRestricted(): void
    {
        $this->login();
        $this->client->request('POST', $this->editUrl(), ['company' => ['name' => 'X']]);
        self::assertFalse($this->redirectedToTariff(), 'нет тарифа — без ограничений');

        $this->subscribe(TariffCode::START, '2027-02-08', '2027-03-07'); // льготные дни до 12.03
        $this->client->request('POST', $this->editUrl(), ['company' => ['name' => 'X']]);
        self::assertFalse($this->redirectedToTariff(), 'льготные дни — без ограничений');
    }

    public function testPaymentLiftsRestrictionImmediately(): void
    {
        $this->subscribe(TariffCode::START, '2027-01-02', '2027-02-01');
        $this->login();
        $this->client->request('POST', $this->editUrl(), ['company' => ['name' => 'X']]);
        self::assertResponseRedirects('/company/price', 303);

        (static::getContainer()->get(RegisterPaymentAction::class))(new RegisterPaymentCommand(
            Uuid::uuid7()->toString(),
            (string) $this->company->getId(),
            TariffCode::START,
            1,
            497_000,
            $this->clock->now(),
            PaymentSource::MANUAL_INVOICE,
        ));
        $this->em()->clear();

        $this->client->request('POST', $this->editUrl(), ['company' => ['name' => 'X']]);
        self::assertFalse($this->redirectedToTariff());
    }

    public function testDisabledFlagOnlyLogs(): void
    {
        $this->subscribe(TariffCode::START, '2027-01-02', '2027-02-01');
        $container = static::getContainer();
        $container->set(ReadOnlyModeSubscriber::class, new ReadOnlyModeSubscriber(
            $container->get(Security::class),
            $container->get(ActiveCompanyService::class),
            $container->get(BillingFacadeInterface::class),
            $this->clock,
            $container->get(UrlGeneratorInterface::class),
            new NullLogger(),
            false,
        ));
        $this->login();

        $this->client->request('POST', $this->editUrl(), ['company' => ['name' => 'X']]);

        self::assertFalse($this->redirectedToTariff());
    }

    private function subscribe(TariffCode $code, string $from, string $until): void
    {
        $tariff = static::getContainer()->get(TariffRepository::class)->findOneByCode($code);
        self::assertInstanceOf(Tariff::class, $tariff);
        $this->em()->persist(CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany((string) $this->company->getId())->onTariff($tariff)->paid($from, $until)->build());
        $this->em()->flush();
        $this->em()->clear();
    }

    private function login(): void
    {
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', $this->company->getId());
    }

    private function editUrl(): string
    {
        return '/company/'.$this->company->getId().'/edit';
    }

    private function redirectedToTariff(): bool
    {
        $response = $this->client->getResponse();

        return $response->isRedirect() && str_ends_with((string) $response->headers->get('Location'), '/company/price');
    }

    private function companyName(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT name FROM companies WHERE id = ?', [$this->company->getId()]);
    }
}
