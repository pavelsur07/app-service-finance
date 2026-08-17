<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Finance\Entity\PLCategory;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class PlReportPreviewControllerTest extends WebTestCaseBase
{
    public function testPreviewOpensAndSeedsPlStructure(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->withEmail('pl-report-preview@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', '/finance/report/preview');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $this->em()->getRepository(PLCategory::class)->count(['company' => $company]),
        );
    }
}
