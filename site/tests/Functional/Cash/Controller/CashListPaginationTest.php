<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Accounts\MoneyFund;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class CashListPaginationTest extends WebTestCaseBase
{
    /** @return iterable<array{string,string,string}> */
    public static function lists(): iterable
    {
        yield 'accounts' => ['/accounts/', 'a[href*="/accounts/"][href*="/edit"]', 'account'];
        yield 'funds' => ['/finance/funds', 'a[href*="/movement"]', 'fund'];
        yield 'profiles' => ['/cash/import/file/profiles', 'a[href*="/profiles/"][href*="/edit"]', 'profile'];
        yield 'calendar' => ['/finance/payment-calendar?from=2026-10-01&to=2026-10-31', 'a[href*="/payment-calendar/"][href*="/edit"]', 'plan'];
    }

    #[DataProvider('lists')]
    public function testEveryRecordIsAccessibleWithStableOrder(string $url, string $selector, string $kind): void
    {
        [$client] = $this->context($kind, 51);
        $first = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $ids = $first->filter($selector)->each(static fn ($a) => $a->attr('href'));
        self::assertCount(50, $ids);
        self::assertCount(1, $first->filter('nav[aria-label="Пагинация"]'));
        self::assertCount(1, $first->filter('a[aria-label="Вперёд"]'));
        $series = $first->filter('[data-forecast-series]')->count() ? $first->filter('[data-forecast-series]')->attr('data-forecast-series') : null;
        $second = $client->request('GET', $url.(str_contains($url, '?') ? '&' : '?').'page=2');
        self::assertResponseIsSuccessful();
        $tail = $second->filter($selector)->each(static fn ($a) => $a->attr('href'));
        self::assertCount(1, $tail);
        self::assertSame([], array_intersect($ids, $tail));
        if ('plan' === $kind) {
            self::assertSame($series, $second->filter('[data-forecast-series]')->attr('data-forecast-series'));
            self::assertCount(52, $second->filter('#filter-account option'), 'Form choices remain complete.');
            self::assertSame('Friendly name', $second->filter('#filter-counterparty option')->last()->text());
        }
        $small = $client->request('GET', $url.(str_contains($url, '?') ? '&' : '?').'page=2&limit=2');
        self::assertCount(2, $small->filter($selector));
        $next = $small->filter('a[aria-label="Вперёд"]')->attr('href');
        self::assertNotNull($next);
        parse_str((string) parse_url($next, \PHP_URL_QUERY), $query);
        self::assertSame('2', $query['limit']);
        self::assertSame('3', $query['page']);
        if ('plan' === $kind) {
            self::assertSame('2026-10-01', $query['from']);
            self::assertSame('2026-10-31', $query['to']);
            self::assertCount(1, $small->filter('form[method="get"] input[name="limit"][value="2"]'));
            self::assertCount(0, $small->filter('form[method="get"] input[name="page"]'));
            self::assertStringContainsString('Итого на странице:', $small->text());
        }
    }

    #[DataProvider('lists')]
    public function testInvalidPaginationReturns422(string $url, string $selector, string $kind): void
    {
        [$client] = $this->context($kind, 1);
        foreach (['page=0', 'page=-1', 'page=abc', 'page[]=1', 'page=', 'page=2', 'limit=0', 'limit=201', 'limit[]=50', 'limit=1.5'] as $query) {
            $client->request('GET', $url.(str_contains($url, '?') ? '&' : '?').$query);
            self::assertSame(422, $client->getResponse()->getStatusCode(), $url.' '.$query);
        }
    }

    #[DataProvider('emptyCalendarCases')]
    public function testCalendarDistinguishesEmptyPeriodFromNoPlans(bool $hasPlans): void
    {
        [$client] = $this->context('plan', $hasPlans ? 1 : 0);
        $crawler = $client->request('GET', '/finance/payment-calendar?from=2026-10-01&to=2026-10-05&limit=2');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($hasPlans ? 'Нет платежей по выбранным фильтрам' : 'Пока нет плановых платежей', $crawler->text());
        self::assertCount(1, $crawler->filter('#filter-from'));
    }

    /** @return iterable<array{bool}> */
    public static function emptyCalendarCases(): iterable
    {
        yield [false];
        yield [true];
    }

    #[DataProvider('lists')]
    public function testReadOnlyMemberCanReadButCannotMutate(string $url, string $selector, string $kind): void
    {
        [$client, $company] = $this->context($kind, 1);
        $role = new \App\Company\Entity\CompanyRole(Uuid::uuid7()->toString(), 'Read finance', ['finance' => 'read'], $company);
        $reader = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->withRoles(['ROLE_COMPANY_USER'])->build();
        $member = \App\Tests\Builders\Company\CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($reader)->withRole(\App\Company\Entity\CompanyMember::ROLE_OPERATOR)->withStatus(\App\Company\Entity\CompanyMember::STATUS_ACTIVE)->withAccessRole($role)->build();
        foreach ([$role, $reader, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $client->loginUser($reader);
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.content-max')->filter('a[href*="/new"], a[href*="/movement"], button[data-confirm-action], form[method="post"][action*="/status"]'));
        $mutation = match ($kind) {
            'account' => '/accounts/new',
            'fund' => '/finance/funds/new',
            'profile' => '/cash/import/file/profiles/new',
            'plan' => '/finance/payment-calendar/new',
            default => throw new \LogicException('Unknown list fixture.'),
        };
        $client->request('POST', $mutation);
        self::assertContains($client->getResponse()->getStatusCode(), [403, 404]);
    }

    public function testCalendarEditLinksReturnToListWhenMorePagesExist(): void
    {
        [$client, $company] = $this->context('plan', 2);
        $id = $this->em()->getConnection()->fetchOne('SELECT id FROM payment_plan WHERE company_id = :company ORDER BY id LIMIT 1', ['company' => $company->getId()]);
        $crawler = $client->request('GET', '/finance/payment-calendar/'.$id.'/edit?from=2026-10-01&to=2026-10-31&limit=1');
        self::assertResponseIsSuccessful();
        $next = $crawler->filter('a[aria-label="Вперёд"]')->attr('href');
        self::assertNotNull($next);
        self::assertSame('/finance/payment-calendar', parse_url($next, \PHP_URL_PATH));
        parse_str((string) parse_url($next, \PHP_URL_QUERY), $query);
        self::assertSame('1', $query['limit']);
        self::assertSame('2', $query['page']);
        self::assertSame('2026-10-01', $query['from']);
    }

    public function testCalendarEditWithoutDateReportsErrorWithoutChangingData(): void
    {
        [$client, $company] = $this->context('plan', 1);
        $id = $this->em()->getConnection()->fetchOne('SELECT id FROM payment_plan WHERE company_id = :company ORDER BY id LIMIT 1', ['company' => $company->getId()]);
        $this->em()->getConnection()->executeStatement('UPDATE payment_plan SET expected_at = NULL WHERE id = :id AND company_id = :company', ['id' => $id, 'company' => $company->getId()]);
        $this->em()->clear();
        $client->request('GET', '/finance/payment-calendar/'.$id.'/edit?from=2026-10-01&to=2026-10-31&limit=2');
        self::assertResponseRedirects('/finance/payment-calendar?limit=2&from=2026-10-01&to=2026-10-31');
        $client->followRedirect();
        self::assertStringContainsString('У платежа не указана дата', (string) $client->getResponse()->getContent());
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT expected_at FROM payment_plan WHERE id = :id', ['id' => $id]));
    }

    /** @return array{\Symfony\Bundle\FrameworkBundle\KernelBrowser, \App\Company\Entity\Company} */
    private function context(string $kind, int $count): array
    {
        $client = self::createClient();
        $user = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($user)->build();
        $foreign = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($user)->build();
        foreach ([$user, $company, $foreign] as $entity) {
            $this->em()->persist($entity);
        }
        foreach ([$company, $foreign] as $owner) {
            $category = (new CashflowCategory(Uuid::uuid7()->toString(), $owner))->setName('Category');
            $this->em()->persist($category);
            if ('plan' === $kind) {
                $counterparty = \App\Tests\Builders\Company\CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($owner)->withInn(null)->withName('Legal name')->build();
                $counterparty->setManualDisplayName('Friendly name');
                $this->em()->persist($counterparty);
            }
            for ($i = 0; $i < ($owner === $company ? $count : 1); ++$i) {
                $id = Uuid::uuid7()->toString();
                $entity = match ($kind) {
                    'account' => MoneyAccountBuilder::aMoneyAccount()->withId($id)->forCompany($owner)->withName(sprintf('Account %03d', $i))->build(),
                    'fund' => new MoneyFund($id, $owner, sprintf('Fund %03d', $i), 'RUB'),
                    'profile' => new CashFileImportProfile($id, $owner, 'Profile'),
                    'plan' => new PaymentPlan($id, $owner, $category, new \DateTimeImmutable('2026-10-10'), '100.00'),
                    default => throw new \LogicException('Unknown list fixture.'),
                };
                $this->em()->persist($entity);
                if ($entity instanceof PaymentPlan) {
                    $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($owner)->withName('Account '.$i)->build();
                    $this->em()->persist($account);
                    $entity->setMoneyAccount($account);
                }
            }
        }
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return [$client, $company];
    }
}
