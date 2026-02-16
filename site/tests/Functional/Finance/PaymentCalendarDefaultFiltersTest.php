<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class PaymentCalendarDefaultFiltersTest extends WebTestCaseBase
{
    public function testIndexPreFillsDefaultPeriodWhenQueryIsMissing(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->loginWithActiveCompany($client, $user, $company->getId());
        $client->request('GET', '/finance/payment-calendar');

        self::assertResponseIsSuccessful();

        $today = new \DateTimeImmutable('today');
        $expectedFrom = $today->modify('monday this week')->format('Y-m-d');
        $expectedTo = $today->modify('+60 days')->format('Y-m-d');

        self::assertSelectorExists(sprintf('input#filter-from[value="%s"]', $expectedFrom));
        self::assertSelectorExists(sprintf('input#filter-to[value="%s"]', $expectedTo));
    }

    /** @return array{0: \App\Company\Entity\User, 1: \App\Company\Entity\Company} */
    private function prepareCompanyContext(): array
    {
        $this->resetDb();
        $em = $this->em();

        $user = UserBuilder::aUser()
            ->withIndex(random_int(1000, 9999))
            ->asCompanyOwner()
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withIndex(random_int(1000, 9999))
            ->withOwner($user)
            ->build();

        $em->persist($user);
        $em->persist($company);
        $em->flush();

        return [$user, $company];
    }

    private function loginWithActiveCompany(object $client, object $user, string $companyId): void
    {
        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $companyId);
        $session->save();
    }
}
