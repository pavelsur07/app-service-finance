<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Shared\Service\RateLimiter\RegistrationRateLimiter;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class PublicRegistrationFlowTest extends WebTestCaseBase
{
    public function testPublicRegistrationCreatesUserCompanyAndOwnerCompanyMember(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $client->getContainer()->set(RegistrationRateLimiter::class, new RegistrationRateLimiter());
        $client->setServerParameter('REMOTE_ADDR', $this->uniqueClientIp());

        $email = 'owner-registration@example.test';
        $companyName = 'Registration LLC';
        $crawler = $client->request('GET', '/register');

        $form = $crawler->selectButton('Создать аккаунт')->form([
            'registration_form[companyName]' => $companyName,
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'password123',
            'registration_form[agreeTerms]' => 1,
            'registration_form[website]' => '',
        ]);
        $client->submit($form);

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

    private function uniqueClientIp(): string
    {
        return sprintf(
            '2001:db8:%x:%x:%x:%x::1',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }
}
