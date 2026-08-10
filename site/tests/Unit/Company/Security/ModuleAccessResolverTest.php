<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Security;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Company\Security\ModuleAccessResolver;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ModuleAccessResolverTest extends TestCase
{
    public function testCompanyOwnerHasWriteAccessToAllModules(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        $resolver = $this->createResolver($owner, $company, null);

        foreach (Module::cases() as $module) {
            self::assertTrue($resolver->allows($module, AccessLevel::WRITE), $module->value);
        }
    }

    public function testMemberWithReadOnlyFinanceRole(): void
    {
        [$user, $company] = $this->memberUserAndCompany();
        $role = new CompanyRole(
            '33333333-3333-4333-8333-333333333333',
            'Только чтение финансов',
            [Module::FINANCE->value => AccessLevel::READ->value],
        );
        $member = $this->buildMember($company, $user, $role);

        $resolver = $this->createResolver($user, $company, $member);

        self::assertTrue($resolver->allows(Module::FINANCE, AccessLevel::READ));
        self::assertFalse($resolver->allows(Module::FINANCE, AccessLevel::WRITE));
        self::assertFalse($resolver->allows(Module::MARKETPLACE, AccessLevel::READ));
    }

    public function testUserWithoutMembershipIsDenied(): void
    {
        [$user, $company] = $this->memberUserAndCompany();

        $resolver = $this->createResolver($user, $company, null);

        self::assertFalse($resolver->allows(Module::FINANCE, AccessLevel::READ));
    }

    public function testMissingActiveCompanyIsDenied(): void
    {
        $user = UserBuilder::aUser()->build();

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $activeCompanyService = $this->createMock(ActiveCompanyService::class);
        $activeCompanyService->method('getActiveCompany')->willThrowException(new NotFoundHttpException());
        $activeCompanyService->method('getActiveMembership')->willReturn(null);

        $resolver = new ModuleAccessResolver($security, $activeCompanyService, new NullLogger());

        self::assertFalse($resolver->allows(Module::FINANCE, AccessLevel::READ));
    }

    public function testAnonymousUserIsDenied(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);
        $activeCompanyService = $this->createMock(ActiveCompanyService::class);
        $activeCompanyService->expects(self::never())->method('getActiveCompany');

        $resolver = new ModuleAccessResolver($security, $activeCompanyService, new NullLogger());

        self::assertFalse($resolver->allows(Module::FINANCE, AccessLevel::READ));
    }

    public function testUnknownLevelInPermissionsTreatedAsNone(): void
    {
        [$user, $company] = $this->memberUserAndCompany();
        $role = new CompanyRole('33333333-3333-4333-8333-333333333333', 'Шаблон', []);
        // Обходим валидацию сеттера, чтобы смоделировать неконсистентные данные в БД.
        $reflection = new \ReflectionProperty(CompanyRole::class, 'permissions');
        $reflection->setValue($role, [Module::FINANCE->value => 'superuser']);

        $member = $this->buildMember($company, $user, $role);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $resolver = $this->createResolver($user, $company, $member, $logger);

        self::assertFalse($resolver->allows(Module::FINANCE, AccessLevel::READ));
    }

    public function testRoleOfAnotherCompanyIsDenied(): void
    {
        [$user, $company] = $this->memberUserAndCompany();
        $anotherCompany = CompanyBuilder::aCompany()
            ->withIndex(2)
            ->withOwner(UserBuilder::aUser()->withIndex(3)->build())
            ->build();
        $role = new CompanyRole(
            '33333333-3333-4333-8333-333333333333',
            'Чужой шаблон',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
            $anotherCompany,
        );
        $member = $this->buildMember($company, $user, $role);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $resolver = $this->createResolver($user, $company, $member, $logger);

        self::assertFalse($resolver->allows(Module::FINANCE, AccessLevel::READ));
    }

    public function testLegacyOperatorWithoutAccessRoleKeepsFullAccess(): void
    {
        [$user, $company] = $this->memberUserAndCompany();
        $member = $this->buildMember($company, $user, null);

        $resolver = $this->createResolver($user, $company, $member);

        self::assertTrue($resolver->allows(Module::FINANCE, AccessLevel::WRITE));
        self::assertTrue($resolver->allows(Module::ADMIN, AccessLevel::WRITE));
    }

    public function testResultIsMemoized(): void
    {
        [$user, $company] = $this->memberUserAndCompany();
        $member = $this->buildMember($company, $user, null);

        $security = $this->createMock(Security::class);
        $security->expects(self::atLeastOnce())->method('getUser')->willReturn($user);
        $activeCompanyService = $this->createMock(ActiveCompanyService::class);
        $activeCompanyService->expects(self::atLeastOnce())->method('getActiveCompany')->willReturn($company);
        // Дорогой lookup членства выполняется один раз — результат мемоизирован.
        $activeCompanyService->expects(self::once())->method('getActiveMembership')->willReturn($member);

        $resolver = new ModuleAccessResolver($security, $activeCompanyService, new NullLogger());

        $resolver->allows(Module::FINANCE, AccessLevel::READ);
        $resolver->allows(Module::MARKETPLACE, AccessLevel::WRITE);
    }

    public function testLevelsAreInvalidatedWhenActiveCompanyChanges(): void
    {
        [$user, $company] = $this->memberUserAndCompany();
        $member = $this->buildMember($company, $user, null);

        $otherCompany = CompanyBuilder::aCompany()
            ->withIndex(9)
            ->withOwner(UserBuilder::aUser()->withIndex(9)->build())
            ->build();

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $activeCompanyService = $this->createMock(ActiveCompanyService::class);
        // Второй вызов — после переключения активной компании.
        $activeCompanyService
            ->expects(self::exactly(2))
            ->method('getActiveCompany')
            ->willReturnOnConsecutiveCalls($company, $otherCompany);
        $activeCompanyService
            ->expects(self::exactly(2))
            ->method('getActiveMembership')
            // Во второй компании членства нет.
            ->willReturnOnConsecutiveCalls($member, null);

        $resolver = new ModuleAccessResolver($security, $activeCompanyService, new NullLogger());

        // Первая компания: legacy OPERATOR — полный доступ.
        self::assertTrue($resolver->allows(Module::FINANCE, AccessLevel::WRITE));
        // Компания сменилась, членства в ней нет — уровни пересчитаны, доступа нет.
        self::assertFalse($resolver->allows(Module::FINANCE, AccessLevel::READ));
    }

    public function testNoneIsNotARequestableLevel(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        $resolver = $this->createResolver($owner, $company, null);

        // Даже владельцу компании allows($module, NONE) не грантит: NONE — отсутствие права.
        self::assertFalse($resolver->allows(Module::FINANCE, AccessLevel::NONE));
    }

    public function testFailurePathDoesNotPoisonCache(): void
    {
        [$user, $company] = $this->memberUserAndCompany();
        $member = $this->buildMember($company, $user, null);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $activeCompanyService = $this->createMock(ActiveCompanyService::class);
        // success → transient failure → success для одной и той же (user, company).
        $calls = 0;
        $activeCompanyService
            ->expects(self::exactly(3))
            ->method('getActiveCompany')
            ->willReturnCallback(static function () use (&$calls, $company): Company {
                ++$calls;
                if (2 === $calls) {
                    throw new NotFoundHttpException();
                }

                return $company;
            });
        // Членство запрашивается один раз: успешный результат закэширован и переживает failure.
        $activeCompanyService
            ->expects(self::once())
            ->method('getActiveMembership')
            ->willReturn($member);

        $resolver = new ModuleAccessResolver($security, $activeCompanyService, new NullLogger());

        self::assertTrue($resolver->allows(Module::FINANCE, AccessLevel::WRITE));
        self::assertFalse($resolver->allows(Module::FINANCE, AccessLevel::READ));
        self::assertTrue($resolver->allows(Module::FINANCE, AccessLevel::WRITE));
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function memberUserAndCompany(): array
    {
        $owner = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $user = UserBuilder::aUser()->withIndex(2)->build();

        return [$user, $company];
    }

    private function buildMember(Company $company, User $user, ?CompanyRole $accessRole): CompanyMember
    {
        return CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($user)
            ->withRole(CompanyMember::ROLE_OPERATOR)
            ->withAccessRole($accessRole)
            ->build();
    }

    private function createResolver(
        User $user,
        Company $company,
        ?CompanyMember $member,
        ?LoggerInterface $logger = null,
    ): ModuleAccessResolver {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $activeCompanyService = $this->createMock(ActiveCompanyService::class);
        $activeCompanyService->method('getActiveCompany')->willReturn($company);
        $activeCompanyService->method('getActiveMembership')->willReturn($member);

        return new ModuleAccessResolver($security, $activeCompanyService, $logger ?? new NullLogger());
    }
}
