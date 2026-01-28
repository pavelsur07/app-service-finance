<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Entity\CompanyMember;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use PHPUnit\Framework\TestCase;

final class CompanyMemberEntityTest extends TestCase
{
    public function testCreateMemberHasDefaults(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $user = UserBuilder::aUser()->build();

        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($user)
            ->build();

        self::assertSame($company, $member->getCompany());
        self::assertSame($user, $member->getUser());
        self::assertSame(CompanyMember::ROLE_OPERATOR, $member->getRole());
        self::assertSame(CompanyMember::STATUS_ACTIVE, $member->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $member->getCreatedAt());
    }

    public function testDisableEnableChangesStatus(): void
    {
        $member = CompanyMemberBuilder::aMember()->build();

        $member->disable();

        self::assertSame(CompanyMember::STATUS_DISABLED, $member->getStatus());

        $member->enable();

        self::assertSame(CompanyMember::STATUS_ACTIVE, $member->getStatus());
    }
}
