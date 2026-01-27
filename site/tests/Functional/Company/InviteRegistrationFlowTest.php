<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyInvite;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Company\Service\InviteTokenService;
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

        $csrfManager = $client->getContainer()->get('security.csrf.token_manager');
        $token = $csrfManager->getToken('registration_form')->getValue();

        $client->request('POST', sprintf('/register?invite=%s', $plainToken), [
            'registration_form' => [
                'email' => $inviteEmail,
                'plainPassword' => 'password123',
                'agreeTerms' => 1,
                'website' => '',
                '_token' => $token,
            ],
        ]);

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
}
