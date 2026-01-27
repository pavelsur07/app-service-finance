<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Entity\CompanyInvite;
use App\Tests\Builders\Company\CompanyInviteBuilder;
use App\Tests\Builders\Company\UserBuilder;
use PHPUnit\Framework\TestCase;

final class CompanyInviteEntityTest extends TestCase
{
    public function testPendingStatus(): void
    {
        $now = new \DateTimeImmutable('2025-01-01 10:00:00+00:00');
        $invite = CompanyInviteBuilder::anInvite()
            ->withPending()
            ->withExpiresAt($now->modify('+1 day'))
            ->build();

        self::assertSame(CompanyInvite::STATUS_PENDING, $invite->getStatus($now));
    }

    public function testExpiredStatus(): void
    {
        $now = new \DateTimeImmutable('2025-01-01 10:00:00+00:00');
        $invite = CompanyInviteBuilder::anInvite()
            ->withPending()
            ->withExpiresAt($now->modify('-1 day'))
            ->build();

        self::assertSame(CompanyInvite::STATUS_EXPIRED, $invite->getStatus($now));
    }

    public function testAcceptedStatus(): void
    {
        $invite = CompanyInviteBuilder::anInvite()
            ->withAcceptedAt(new \DateTimeImmutable('2025-01-02 10:00:00+00:00'))
            ->build();

        self::assertSame(CompanyInvite::STATUS_ACCEPTED, $invite->getStatus());
    }

    public function testRevokedStatus(): void
    {
        $invite = CompanyInviteBuilder::anInvite()
            ->withRevokedAt(new \DateTimeImmutable('2025-01-02 10:00:00+00:00'))
            ->build();

        self::assertSame(CompanyInvite::STATUS_REVOKED, $invite->getStatus());
    }

    public function testAcceptSetsAcceptedAtAndAcceptedBy(): void
    {
        $invite = CompanyInviteBuilder::anInvite()->build();
        $user = UserBuilder::aUser()->build();
        $acceptedAt = new \DateTimeImmutable('2025-01-02 10:00:00+00:00');

        $invite->accept($user, $acceptedAt);

        self::assertSame($acceptedAt, $invite->getAcceptedAt());
        self::assertSame($user, $invite->getAcceptedByUser());
    }
}
