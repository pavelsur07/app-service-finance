<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyInvite;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Company\Service\InviteTokenService;
use App\Shared\Service\RateLimiter\RegistrationRateLimiter;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyInviteBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class InviteRegistrationFlowTest extends WebTestCaseBase
{
    public function testInviteRegistrationCreatesMemberWithoutNewCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $client->getContainer()->set(RegistrationRateLimiter::class, new RegistrationRateLimiter());
        $client->setServerParameter('REMOTE_ADDR', $this->uniqueClientIp());

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $tokenService = new InviteTokenService();
        $plainToken = 'registration-token';
        $tokenHash = $tokenService->hashToken($plainToken);
        $inviteEmail = 'newuser@example.test';
        $invite = CompanyInviteBuilder::anInvite()
            ->withCompany($company)
            ->withCreatedBy($owner)
            ->withEmail($inviteEmail)
            ->withTokenHash($tokenHash)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($invite);
        $em->flush();

        $crawler = $client->request('GET', sprintf('/register?invite=%s', $plainToken));
        $form = $crawler->selectButton('Создать аккаунт')->form([
            'registration_form[email]' => $inviteEmail,
            'registration_form[plainPassword]' => 'password123',
            'registration_form[agreeTerms]' => 1,
            'registration_form[website]' => '',
        ]);
        $client->submit($form);

        self::assertTrue($client->getResponse()->isRedirect());

        $statusCode = $client->getResponse()->getStatusCode();
        self::assertNotSame(401, $statusCode);
        self::assertNotSame(403, $statusCode);

        $userRepository = $this->em()->getRepository(User::class);
        $registeredUser = $userRepository->findOneBy(['email' => $inviteEmail]);

        self::assertNotNull($registeredUser);

        $companyRepository = $this->em()->getRepository(Company::class);
        self::assertSame(1, $companyRepository->count([]));

        $memberRepository = $this->em()->getRepository(CompanyMember::class);
        $member = $memberRepository->findOneByCompanyAndUser($company, $registeredUser);

        self::assertNotNull($member);

        $updatedInvite = $this->em()->getRepository(CompanyInvite::class)->find($invite->getId());
        self::assertNotNull($updatedInvite);
        self::assertNotNull($updatedInvite->getAcceptedAt());
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
