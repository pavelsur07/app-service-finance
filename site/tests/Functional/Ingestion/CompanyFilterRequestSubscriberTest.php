<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class CompanyFilterRequestSubscriberTest extends WebTestCaseBase
{
    public function testAuthenticatedRequestWithoutActiveCompanyDoesNotEnableCompanyFilter(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = UserBuilder::aUser()
            ->withEmail('admin-no-company@example.test')
            ->withRoles(['ROLE_ADMIN'])
            ->build();

        $em = $this->em();
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin, 'admin');
        $client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertFalse($em->getFilters()->isEnabled('company'));
    }
}
