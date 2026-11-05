<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;

final class CompanyDeletionEntityTest extends TestCase
{
    private const USER_ID = '018f0000-0000-7000-8000-000000000001';
    private const OTHER_USER_ID = '018f0000-0000-7000-8000-000000000002';

    public function testNewCompanyIsNotMarkedForDeletion(): void
    {
        $company = CompanyBuilder::aCompany()->build();

        self::assertFalse($company->isDeletionRequested());
        self::assertNull($company->getDeletionRequestedAt());
        self::assertNull($company->getDeletionDueAt());
    }

    public function testRequestDeletionMarksCompanyAndDueDateIsThirtyDaysLater(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $now = new \DateTimeImmutable('2026-11-05 10:15:00');

        self::assertTrue($company->requestDeletion($now, self::USER_ID));

        self::assertTrue($company->isDeletionRequested());
        self::assertEquals($now, $company->getDeletionRequestedAt());
        self::assertSame(self::USER_ID, $company->getDeletionRequestedBy());
        self::assertSame('2026-12-05 10:15:00', $company->getDeletionDueAt()?->format('Y-m-d H:i:s'));
    }

    public function testRepeatedRequestKeepsFirstDateAndRequester(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $company->requestDeletion(new \DateTimeImmutable('2026-11-05 10:00:00'), self::USER_ID);

        self::assertFalse($company->requestDeletion(new \DateTimeImmutable('2026-11-20 10:00:00'), self::OTHER_USER_ID));

        self::assertSame('2026-11-05', $company->getDeletionRequestedAt()?->format('Y-m-d'));
        self::assertSame(self::USER_ID, $company->getDeletionRequestedBy());
    }

    public function testCancelDeletionClearsMarkAndRepeatIsNoop(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $company->requestDeletion(new \DateTimeImmutable('2026-11-05 10:00:00'), self::USER_ID);

        self::assertTrue($company->cancelDeletion());
        self::assertFalse($company->isDeletionRequested());
        self::assertNull($company->getDeletionRequestedBy());
        self::assertFalse($company->cancelDeletion());
    }

    public function testRequestDeletionRejectsInvalidUserId(): void
    {
        $company = CompanyBuilder::aCompany()->build();

        $this->expectException(\InvalidArgumentException::class);
        $company->requestDeletion(new \DateTimeImmutable(), 'not-a-uuid');
    }
}
