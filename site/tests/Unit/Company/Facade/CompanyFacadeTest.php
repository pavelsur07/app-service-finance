<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Facade;

use App\Company\Entity\CompanyMember;
use App\Company\Facade\CompanyFacade;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Repository\CounterpartyRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Company\Service\CompanyOwnerAccountCreator;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CompanyFacadeTest extends TestCase
{
    public function testListAccessibleCompaniesForUserMergesOwnedAndActiveMemberCompanies(): void
    {
        $user = UserBuilder::aUser()->build();
        $owned = CompanyBuilder::aCompany()->withIndex(1)->build();
        $memberCompany = CompanyBuilder::aCompany()->withIndex(2)->build();
        $member = new CompanyMember(Uuid::uuid7()->toString(), $memberCompany, $user, CompanyMember::ROLE_OPERATOR);

        $facade = $this->facade(
            findByUserId: [(string) $user->getId() => [$owned]],
            findActiveByUserId: [(string) $user->getId() => [$member]],
        );

        self::assertSame(
            [
                ['id' => (string) $owned->getId(), 'name' => (string) $owned->getName()],
                ['id' => (string) $memberCompany->getId(), 'name' => (string) $memberCompany->getName()],
            ],
            $facade->listAccessibleCompaniesForUser((string) $user->getId()),
        );
    }

    public function testListAccessibleCompaniesForUserDeduplicatesCompanyOwnedAndAlsoMember(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();
        $member = new CompanyMember(Uuid::uuid7()->toString(), $company, $user, CompanyMember::ROLE_OPERATOR);

        $facade = $this->facade(
            findByUserId: [(string) $user->getId() => [$company]],
            findActiveByUserId: [(string) $user->getId() => [$member]],
        );

        self::assertSame(
            [['id' => (string) $company->getId(), 'name' => (string) $company->getName()]],
            $facade->listAccessibleCompaniesForUser((string) $user->getId()),
        );
    }

    public function testUserHasAccessIsTrueForOwnedCompany(): void
    {
        $user = UserBuilder::aUser()->build();
        $owned = CompanyBuilder::aCompany()->withIndex(1)->build();

        $facade = $this->facade(
            findByUserId: [(string) $user->getId() => [$owned]],
            findActiveByUserId: [(string) $user->getId() => []],
        );

        self::assertTrue($facade->userHasAccess((string) $owned->getId(), (string) $user->getId()));
    }

    public function testUserHasAccessIsTrueForActiveMemberCompany(): void
    {
        $user = UserBuilder::aUser()->build();
        $memberCompany = CompanyBuilder::aCompany()->withIndex(2)->build();
        $member = new CompanyMember(Uuid::uuid7()->toString(), $memberCompany, $user, CompanyMember::ROLE_OPERATOR);

        $facade = $this->facade(
            findByUserId: [(string) $user->getId() => []],
            findActiveByUserId: [(string) $user->getId() => [$member]],
        );

        self::assertTrue($facade->userHasAccess((string) $memberCompany->getId(), (string) $user->getId()));
    }

    public function testUserHasAccessIsFalseWithoutOwnershipOrMembership(): void
    {
        $user = UserBuilder::aUser()->build();
        $foreignCompany = CompanyBuilder::aCompany()->withIndex(3)->build();

        $facade = $this->facade(
            findByUserId: [(string) $user->getId() => []],
            findActiveByUserId: [(string) $user->getId() => []],
        );

        self::assertFalse($facade->userHasAccess((string) $foreignCompany->getId(), (string) $user->getId()));
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
        $facade = $this->facade([], [], $repository);

        self::assertTrue($facade->isOwner((string) $company->getId(), (string) $owner->getId()));
        self::assertFalse($facade->isOwner((string) $company->getId(), (string) $otherOwner->getId()));
        self::assertFalse($facade->isOwner(Uuid::uuid7()->toString(), (string) $owner->getId()));
        self::assertFalse($facade->isOwner('invalid', (string) $owner->getId()));
        self::assertFalse($facade->isOwner((string) $company->getId(), 'invalid'));
    }

    /**
     * @param array<string, list<\App\Company\Entity\Company>> $findByUserId
     * @param array<string, list<CompanyMember>>               $findActiveByUserId
     * @param (CompanyRepository&MockObject)|null              $repository
     */
    private function facade(array $findByUserId, array $findActiveByUserId, ?CompanyRepository $repository = null): CompanyFacade
    {
        $companyRepository = $repository ?? $this->createMock(CompanyRepository::class);
        $companyRepository->method('findByUserId')->willReturnCallback(
            static fn (string $userId): array => $findByUserId[$userId] ?? [],
        );

        $companyMemberRepository = $this->createMock(CompanyMemberRepository::class);
        $companyMemberRepository->method('findActiveByUserId')->willReturnCallback(
            static fn (string $userId): array => $findActiveByUserId[$userId] ?? [],
        );

        return new CompanyFacade(
            $companyRepository,
            (new \ReflectionClass(CompanyOwnerAccountCreator::class))->newInstanceWithoutConstructor(),
            $this->createMock(CounterpartyRepository::class),
            $companyMemberRepository,
            $this->createMock(ProjectDirectionRepository::class),
        );
    }
}
