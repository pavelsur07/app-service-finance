<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Facade;

use App\Company\Application\CreateCompanyOwnerAccountAction;
use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Repository\CounterpartyRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Company\Repository\UserRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CompanyFacadeTest extends TestCase
{
    public function testListAccessibleCompaniesMapsRepositoryResultWithoutLeakingEntities(): void
    {
        $user = UserBuilder::aUser()->build();
        $first = CompanyBuilder::aCompany()->withIndex(1)->build();
        $second = CompanyBuilder::aCompany()->withIndex(2)->build();
        $facade = $this->facade([(string) $user->getId() => [$first, $second]]);

        self::assertSame([
            ['id' => (string) $first->getId(), 'name' => (string) $first->getName()],
            ['id' => (string) $second->getId(), 'name' => (string) $second->getName()],
        ], $facade->listAccessibleCompaniesForUser((string) $user->getId()));
    }

    public function testAccessUsesOnlyTheRequestingUsersAccessibleCompanies(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->build();
        $facade = $this->facade([(string) $user->getId() => [$company]]);

        self::assertTrue($facade->userHasAccess((string) $company->getId(), (string) $user->getId()));
        self::assertFalse($facade->userHasAccess(Uuid::uuid7()->toString(), (string) $user->getId()));
        self::assertFalse($facade->userHasAccess((string) $company->getId(), Uuid::uuid7()->toString()));
        self::assertSame([], $facade->listAccessibleCompaniesForUser(Uuid::uuid7()->toString()));
    }

    public function testOwnerCheckUsesActualCompanyOwnerAndRejectsForeignOrMissingCompany(): void
    {
        $owner = UserBuilder::aUser()->withIndex(901)->build();
        $otherOwner = UserBuilder::aUser()->withIndex(902)->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $repository = $this->createMock(CompanyRepository::class);
        $repository->method('findById')->willReturnCallback(
            static fn (string $id) => $id === $company->getId() ? $company : null,
        );
        $facade = $this->facade([], $repository);

        self::assertTrue($facade->isOwner((string) $company->getId(), (string) $owner->getId()));
        self::assertFalse($facade->isOwner((string) $company->getId(), (string) $otherOwner->getId()));
        self::assertFalse($facade->isOwner(Uuid::uuid7()->toString(), (string) $owner->getId()));
        self::assertFalse($facade->isOwner('invalid', (string) $owner->getId()));
        self::assertFalse($facade->isOwner((string) $company->getId(), 'invalid'));
    }

    /**
     * @param array<string, list<Company>> $accessibleByUser
     * @param (CompanyRepository&MockObject)|null $repository
     */
    private function facade(array $accessibleByUser, ?CompanyRepository $repository = null): CompanyFacade
    {
        $companyRepository = $repository ?? $this->createMock(CompanyRepository::class);
        $companyRepository->method('findAccessibleByUserId')->willReturnCallback(
            static fn (string $userId): array => $accessibleByUser[$userId] ?? [],
        );
        $companyRepository->method('isAccessibleByUser')->willReturnCallback(
            static fn (string $companyId, string $userId): bool => \in_array($companyId, array_map(static fn (Company $company): string => (string) $company->getId(), $accessibleByUser[$userId] ?? []), true),
        );
        $companyMemberRepository = $this->createMock(CompanyMemberRepository::class);

        return new CompanyFacade(
            $companyRepository,
            (new \ReflectionClass(CreateCompanyOwnerAccountAction::class))->newInstanceWithoutConstructor(),
            $this->createMock(CounterpartyRepository::class),
            $companyMemberRepository,
            $this->createMock(ProjectDirectionRepository::class),
            $this->createMock(UserRepository::class),
        );
    }
}
