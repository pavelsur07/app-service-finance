<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Security;

use App\Company\Security\ModuleAccess;
use App\Company\Security\ModuleAccessResolver;
use App\Company\Security\ModuleAccessVoter;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class ModuleAccessVoterTest extends TestCase
{
    public function testAbstainsOnNonModuleAttributes(): void
    {
        $voter = new ModuleAccessVoter($this->createResolver(true));
        $token = $this->createMock(TokenInterface::class);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['ROLE_USER']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['module.unknown.read']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['module.finance']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['module.finance.execute']));
    }

    public function testSupportsAllModuleAccessConstants(): void
    {
        $constants = (new \ReflectionClass(ModuleAccess::class))
            ->getConstants(\ReflectionClassConstant::IS_PUBLIC);
        self::assertCount(10, $constants);

        $voter = new ModuleAccessVoter($this->createResolver(true));
        $token = $this->createMock(TokenInterface::class);

        foreach ($constants as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                $voter->vote($token, null, [$attribute]),
                $attribute,
            );
        }
    }

    public function testDeniedWhenResolverDisallows(): void
    {
        $voter = new ModuleAccessVoter($this->createResolver(false));
        $token = $this->createMock(TokenInterface::class);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, null, [ModuleAccess::FINANCE_WRITE]),
        );
    }

    private function createResolver(bool $allowed): ModuleAccessResolver
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        // $allowed=true — пользователь владеет компанией (полный доступ);
        // $allowed=false — пользователь без членства (deny).
        $user = $allowed ? $owner : UserBuilder::aUser()->withIndex(2)->build();

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $activeCompanyService = $this->createMock(ActiveCompanyService::class);
        $activeCompanyService->method('getActiveCompany')->willReturn($company);
        $activeCompanyService->method('getActiveMembership')->willReturn(null);

        return new ModuleAccessResolver($security, $activeCompanyService, new NullLogger());
    }
}
