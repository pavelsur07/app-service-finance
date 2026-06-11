<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class PublicRegistrationFlowTest extends WebTestCaseBase
{
    public function testPublicRegistrationCreatesUserCompanyAndOwnerCompanyMember(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $csrfManager = $client->getContainer()->get('security.csrf.token_manager');
        $token = $csrfManager->getToken('registration_form')->getValue();
        $email = 'owner-registration@example.test';
        $companyName = 'Registration LLC';

        $client->request('POST', '/register', [
            'registration_form' => [
                'companyName' => $companyName,
                'email' => $email,
                'plainPassword' => 'password123',
                'agreeTerms' => 1,
                'website' => '',
                '_token' => $token,
            ],
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $em = $this->em();
        $userRepository = $em->getRepository(User::class);
        $registeredUser = $userRepository->findOneBy(['email' => $email]);

        self::assertSame(1, $userRepository->count([]));
        self::assertNotNull($registeredUser);

        $companyRepository = $em->getRepository(Company::class);
        $company = $companyRepository->findOneBy(['name' => $companyName]);

        self::assertSame(1, $companyRepository->count([]));
        self::assertNotNull($company);
        self::assertSame($registeredUser->getId(), $company->getUser()?->getId());

        $memberRepository = $em->getRepository(CompanyMember::class);
        $member = $memberRepository->findOneByCompanyAndUser($company, $registeredUser);

        self::assertSame(1, $memberRepository->count([]));
        self::assertNotNull($member);
        self::assertSame($company->getId(), $member->getCompany()->getId());
        self::assertSame($registeredUser->getId(), $member->getUser()->getId());
        self::assertSame(CompanyMember::ROLE_OWNER, $member->getRole());
        self::assertSame(CompanyMember::STATUS_ACTIVE, $member->getStatus());
    }
}
