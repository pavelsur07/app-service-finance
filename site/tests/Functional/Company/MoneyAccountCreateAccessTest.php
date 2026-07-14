<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class MoneyAccountCreateAccessTest extends WebTestCaseBase
{
    public function testCompanyOwnerCanAccessMoneyAccountEndpoint(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();

        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($user)
            ->build();

        $companyId = $company->getId();

        $em->persist($user);
        $em->persist($company);
        $em->flush();
        $em->clear();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);

        $client->request('GET', '/accounts/');

        $statusCode = $client->getResponse()->getStatusCode();
        if (302 === $statusCode) {
            self::assertResponseRedirects();
        } else {
            self::assertResponseStatusCodeSame(200);
        }

        self::assertNotSame(401, $statusCode);
        self::assertNotSame(403, $statusCode);
    }
}
