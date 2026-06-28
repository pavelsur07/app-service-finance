<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class CompanyCreateFlowTest extends WebTestCaseBase
{
    public function testCreateCompanyAddsOwnerCompanyMember(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()
            ->withEmail('owner@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/company/new');

        $form = $crawler->selectButton('Создать')->form([
            'company[name]' => '  Created Company  ',
            'company[inn]' => '1234567890',
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/company/');

        $company = $em->getRepository(Company::class)->findOneBy(['name' => 'Created Company']);
        self::assertNotNull($company);
        self::assertSame($owner->getId(), $company->getUser()?->getId());
        self::assertSame('1234567890', $company->getInn());

        $member = $em->getRepository(CompanyMember::class)->findOneByCompanyAndUser($company, $owner);
        self::assertNotNull($member);
        self::assertSame(CompanyMember::ROLE_OWNER, $member->getRole());
        self::assertSame(CompanyMember::STATUS_ACTIVE, $member->getStatus());
    }
}
