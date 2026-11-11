<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company\Api;

use App\Company\Entity\CompanyMember;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Support\Spa\SpaWebTestCase;
use Ramsey\Uuid\Uuid;

final class SelectActiveCompanyTest extends SpaWebTestCase
{
    public function testSelectionAcceptsOnlyJsonPayloads(): void
    {
        $client = static::createClient();
        $user = $this->user();
        $company = $this->company($user, 'Owned');
        $tokens = $this->login($client, $user);
        $url = 'https://account.vashfindir.ru/api/account/v1/active-company';
        $headers = ['HTTP_ORIGIN' => 'https://account.vashfindir.ru', 'HTTP_X_CSRF_TOKEN' => $tokens['company_select']];
        $client->request('POST', $url, ['targetCompanyId' => $company->getId()], [], $headers + ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        self::assertResponseStatusCodeSame(415);
        $client->request('POST', $url, [], [], $headers + ['CONTENT_TYPE' => 'application/xml'], '<request><targetCompanyId>'.$company->getId().'</targetCompanyId></request>');
        self::assertResponseStatusCodeSame(415);
    }

    public function testSelectionAuthorizesOwnerAndMemberButKeepsContextOnInvalidTargets(): void
    {
        $client = static::createClient();
        $user = $this->user();
        $owned = $this->company($user, 'Owned');
        $memberCompany = $this->company($this->user(), 'Member');
        $foreign = $this->company($this->user(), 'Foreign');
        $deleted = $this->company($user, 'Deleted');
        $deleted->requestDeletion(new \DateTimeImmutable(), (string) $user->getId());
        $disabled = $this->company($this->user(), 'Disabled membership');
        $this->em()->persist(CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($disabled)->withUser($user)->withStatus(CompanyMember::STATUS_DISABLED)->build());
        $this->em()->persist(CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($memberCompany)->withUser($user)->build());
        $this->em()->flush();
        $tokens = $this->login($client, $user);
        $url = 'https://account.vashfindir.ru/api/account/v1/active-company';
        $headers = ['HTTP_ORIGIN' => 'https://account.vashfindir.ru', 'HTTP_X_CSRF_TOKEN' => $tokens['company_select']];
        $client->jsonRequest('POST', $url, ['targetCompanyId' => $memberCompany->getId()], $headers);
        self::assertResponseIsSuccessful();
        self::assertSame($memberCompany->getId(), $this->body($client)['active_company']['id']);
        self::assertSame(['none'], array_values(array_unique($this->body($client)['permissions'])));
        foreach ([$foreign->getId(), $deleted->getId(), $disabled->getId(), Uuid::uuid7()->toString()] as $id) {
            $client->jsonRequest('POST', $url, ['targetCompanyId' => $id], $headers);
            self::assertResponseStatusCodeSame(404);
            $this->get($client, 'session');
            self::assertSame($memberCompany->getId(), $this->body($client)['active_company']['id']);
        }
        $client->jsonRequest('POST', $url, ['targetCompanyId' => 'invalid'], $headers);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', $url, ['targetCompanyId' => $owned->getId()], ['HTTP_ORIGIN' => 'https://account.vashfindir.ru']);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $url, ['targetCompanyId' => $owned->getId()], $headers);
        self::assertResponseIsSuccessful();
        self::assertSame(['write'], array_values(array_unique($this->body($client)['permissions'])));
    }
}
