<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\CompanyInvite;
use App\Company\Entity\CompanyMember;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Service\InviteTokenService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyInviteBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class InviteAcceptExistingUserTest extends WebTestCaseBase
{
    public function testAcceptInviteCreatesMemberAndMarksInviteAccepted(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $existingUser = UserBuilder::aUser()->withEmail('operator@example.test')->build();
        $tokenService = new InviteTokenService();
        $plainToken = 'existing-user-token';
        $tokenHash = $tokenService->hashToken($plainToken);
        $invite = CompanyInviteBuilder::anInvite()
            ->withCompany($company)
            ->withCreatedBy($owner)
            ->withEmail($existingUser->getEmail())
            ->withTokenHash($tokenHash)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($existingUser);
        $em->persist($invite);
        $em->flush();

        $client->loginUser($existingUser);
        $csrfManager = $client->getContainer()->get('security.csrf.token_manager');
        $token = $csrfManager->getToken('invite_accept_'.$invite->getId())->getValue();

        $client->request('POST', sprintf('/invite/%s/accept', $plainToken), [
            '_token' => $token,
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        /** @var CompanyMemberRepository $memberRepository */
        $memberRepository = $this->em()->getRepository(CompanyMember::class);
        $member = $memberRepository->findOneByCompanyAndUser($company, $existingUser);

        self::assertNotNull($member);

        $updatedInvite = $this->em()->getRepository(CompanyInvite::class)->find($invite->getId());
        self::assertNotNull($updatedInvite);
        self::assertNotNull($updatedInvite->getAcceptedAt());
    }
}
