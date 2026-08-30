<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Application;

use App\Company\Application\AssignCompanyMemberAccessRoleAction;
use App\Company\Domain\Service\CompanyAdminWriteGuard;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Exception\CompanyRoleNotAvailableException;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Регрессия: `$roleId` не был захвачен в `use (...)` замыкания транзакции.
 *
 * Ветка гонки «шаблон роли удалён между проверкой в контроллере и блокировкой»
 * должна давать доменное `CompanyRoleNotAvailableException` — так задумано и так
 * написано в комментарии Action. Фактически внутри замыкания `$roleId` был
 * неопределён, превращался в `null`, а конструктор исключения типизирован
 * `string` при `declare(strict_types=1)` — то есть вместо осмысленного отказа
 * возникал `TypeError` и 500.
 *
 * Тест проверяет наблюдаемое поведение: какой тип исключения видит вызывающий
 * код и остаётся ли в сообщении идентификатор роли.
 */
final class AssignCompanyMemberAccessRoleActionTest extends TestCase
{
    private const ROLE_ID = '33333333-3333-7333-8333-333333333333';

    public function testThrowsDomainExceptionWhenRoleDisappearsBeforeLock(): void
    {
        $company = $this->createCompany();
        $role = new CompanyRole(self::ROLE_ID, 'Бухгалтер', [Module::FINANCE->value => AccessLevel::WRITE->value], $company);
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($company->getUser())
            ->build();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $work) => $work($entityManager));
        $entityManager->method('lock');
        $entityManager->method('refresh')
            ->with($role)
            ->willThrowException(new EntityNotFoundException('роль удалена'));

        // Guard на этой ветке не вызывается: refresh() бросает раньше. Он
        // собирается настоящим, потому что класс final и подменять его нечем.
        $action = new AssignCompanyMemberAccessRoleAction(
            $entityManager,
            new CompanyAdminWriteGuard($this->createMemberRepository()),
        );

        $this->expectException(CompanyRoleNotAvailableException::class);
        $this->expectExceptionMessage(self::ROLE_ID);

        $action($member, $role);
    }

    private function createMemberRepository(): CompanyMemberRepository
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn(new ClassMetadata(CompanyMember::class));

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return new CompanyMemberRepository($registry);
    }

    private function createCompany(): Company
    {
        $user = new User('11111111-1111-7111-8111-111111111111');

        return new Company('22222222-2222-7222-8222-222222222222', $user);
    }
}
