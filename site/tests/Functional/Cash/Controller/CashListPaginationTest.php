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
        $series = $first->filter('[data-forecast-series]')->count() ? $first->filter('[data-forecast-series]')->attr('data-forecast-series') : null;
        $second = $client->request('GET', $url.(str_contains($url, '?') ? '&' : '?').'page=2');
        self::assertResponseIsSuccessful();
        $tail = $second->filter($selector)->each(static fn ($a) => $a->attr('href'));
        self::assertCount(1, $tail);
        self::assertSame([], array_intersect($ids, $tail));
        if ('plan' === $kind) {
            self::assertSame($series, $second->filter('[data-forecast-series]')->attr('data-forecast-series'));
            self::assertCount(52, $second->filter('#filter-account option'), 'Form choices remain complete.');
        }
        $small = $client->request('GET', $url.(str_contains($url, '?') ? '&' : '?').'page=2&limit=2');
        self::assertCount(2, $small->filter($selector));
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
            for ($i = 0; $i < ($owner === $company ? $count : 1); ++$i) {
                $id = Uuid::uuid7()->toString();
                $entity = match ($kind) {
                    'account' => MoneyAccountBuilder::aMoneyAccount()->withId($id)->forCompany($owner)->withName(sprintf('Account %03d', $i))->build(),
                    'fund' => new MoneyFund($id, $owner, sprintf('Fund %03d', $i), 'RUB'),
                    'profile' => new CashFileImportProfile($id, $owner, 'Profile'),
                    'plan' => new PaymentPlan($id, $owner, $category, new \DateTimeImmutable('2026-10-10'), '100.00'),
                };
                $this->em()->persist($entity);
                if ('plan' === $kind) {
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
