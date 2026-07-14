<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\CompanyInvite;
use App\Company\Repository\CompanyInviteRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyInviteBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class CompanyMemberInviteFlowTest extends WebTestCaseBase
{
    public function testInviteCreatesCompanyInvite(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $companyId = $company->getId();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);

        $crawler = $client->request('GET', '/company/users');
        $token = (string) $crawler->filter('input[name="company_invite_operator[_token]"]')->attr('value');

        $client->request('POST', '/company/users/invite', [
            'company_invite_operator' => [
                'email' => 'operator@example.test',
                '_token' => $token,
            ],
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        /** @var CompanyInviteRepository $inviteRepository */
        $inviteRepository = self::getContainer()->get(CompanyInviteRepository::class);
        $invite = $inviteRepository->findPendingByCompanyAndEmail(
            $company,
            'operator@example.test',
            new \DateTimeImmutable()
        );

        self::assertNotNull($invite);
    }

    public function testRevokeInviteSetsRevokedAt(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $invite = CompanyInviteBuilder::anInvite()
            ->withCompany($company)
            ->withCreatedBy($owner)
            ->withEmail('operator@example.test')
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($invite);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/company/users');
        $formSelector = sprintf('form[action="/company/invites/%s/revoke"] input[name="_token"]', $invite->getId());
        $token = (string) $crawler->filter($formSelector)->attr('value');

        $client->request('POST', sprintf('/company/invites/%s/revoke', $invite->getId()), [
            '_token' => $token,
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        /** @var CompanyInviteRepository $inviteRepository */
        $inviteRepository = $this->em()->getRepository(CompanyInvite::class);
        $updatedInvite = $inviteRepository->find($invite->getId());

        self::assertNotNull($updatedInvite);
        self::assertNotNull($updatedInvite->getRevokedAt());
    }
}
