<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Tests\Builders\Shared\UserBuilder;
use PHPUnit\Framework\TestCase;

final class UserOwnerRolesTest extends TestCase
{
    public function testUserOwnerRolesAreExpected(): void
    {
        $user = UserBuilder::aUser()->build();

        $roles = $user->getRoles();

        self::assertContains('ROLE_COMPANY_OWNER', $roles);
        self::assertContains('ROLE_USER', $roles);
        self::assertCount(2, $roles);
    }
}
