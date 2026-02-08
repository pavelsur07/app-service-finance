<?php

declare(strict_types=1);

namespace App\Tests\Integration\_Smoke;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class SmokePersistenceTest extends IntegrationTestCase
{
    public function testKernelBootsAndCanPersistUserAndCompany(): void
    {
        $this->assertSame('test', self::$kernel->getEnvironment());

        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();
        $this->em->clear();

        /** @var User|null $savedUser */
        $savedUser = $this->em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($savedUser);
        $this->assertSame($user->getEmail(), $savedUser->getEmail());

        /** @var Company|null $savedCompany */
        $savedCompany = $this->em->getRepository(Company::class)->find($company->getId());
        $this->assertNotNull($savedCompany);
        $this->assertSame($company->getName(), $savedCompany->getName());
    }
}
