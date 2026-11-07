<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

/** Smoke-тест формы нового денежного счёта: хуки assets/pages/money_account.js на месте. */
final class MoneyAccountNewPageTest extends WebTestCaseBase
{
    public function testNewPageRendersFormHooks(): void
    {
        $client = static::createClient();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('money-account-new@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
        $crawler = $client->request('GET', '/accounts/new');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-money-account-form]');
        self::assertCount(1, $form);
        self::assertGreaterThanOrEqual(2, $form->filter('[data-money-account-type]')->count());
        self::assertCount(1, $form->filter('[data-money-account-number-field] input[data-money-account-number]'));
        self::assertCount(1, $form->filter('input[data-money-account-default]'));
        self::assertCount(1, $form->filter('[data-money-account-default-badge]'));
    }
}
