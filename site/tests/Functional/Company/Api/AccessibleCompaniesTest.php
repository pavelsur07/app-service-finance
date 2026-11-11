<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company\Api;

use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Support\Spa\SpaWebTestCase;
use Ramsey\Uuid\Uuid;

final class AccessibleCompaniesTest extends SpaWebTestCase
{
    public function testOnlyOwnedAndActiveMembershipArePaginatedWithoutDuplicates(): void
    {
        $client = static::createClient();
        $user = $this->user();
        $owner = $this->user();
        $first = $this->company($user, 'Alpha');
        $second = $this->company($owner, 'Beta');
        $hidden = $this->company($owner, 'Foreign');
        $deleted = $this->company($user, 'Deleted');
        $deleted->requestDeletion(new \DateTimeImmutable(), (string) $user->getId());
        foreach ([$first, $second] as $company) {
            $this->em()->persist(CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withUser($user)->withCompany($company)->build());
        }
        $this->em()->flush();
        $this->login($client, $user);
        $this->get($client, 'companies?perPage=1&page=1');
        self::assertResponseIsSuccessful();
        self::assertSame([['id' => $first->getId(), 'name' => 'Alpha']], $this->body($client)['data']);
        self::assertSame(['total' => 2, 'page' => 1, 'per_page' => 1, 'pages' => 2], $this->body($client)['meta']);
        $this->get($client, 'companies?perPage=1&page=2');
        self::assertSame($second->getId(), $this->body($client)['data'][0]['id']);
        foreach (['page=0', 'page=9', 'perPage=201', 'perPage=0', 'page=oops'] as $query) {
            $this->get($client, 'companies?'.$query);
            self::assertResponseStatusCodeSame(422);
        }
    }

    public function testEmptyFirstPageIsValid(): void
    {
        $client = static::createClient();
        $this->login($client, $this->user());
        $this->get($client, 'companies');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->body($client)['data']);
        self::assertSame(1, $this->body($client)['meta']['pages']);
        $this->get($client, 'companies?page=2');
        self::assertResponseStatusCodeSame(422);
    }
}
