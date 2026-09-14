<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance;

use App\Company\Entity\CompanyMember;
use App\Company\Facade\CompanyFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class BalanceMemberChoicesTest extends IntegrationTestCase
{
    public function testMemberChoicesAreCompanyScopedAndIncludeOwner(): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $member = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $other = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $membership = new CompanyMember(Uuid::uuid7()->toString(), $company, $member, CompanyMember::ROLE_OPERATOR);
        foreach ([$owner, $member, $company, $other, $membership] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $facade = self::getContainer()->get(CompanyFacade::class);
        $choices = $facade->listMembers((string) $company->getId());
        self::assertEqualsCanonicalizing([(string) $owner->getId(), (string) $member->getId()], array_column($choices, 'id'));
        self::assertSame([(string) $owner->getId()], array_column($facade->listMembers((string) $other->getId()), 'id'));
    }
}
