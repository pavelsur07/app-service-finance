<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Entity;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\MarketplaceType;
use PHPUnit\Framework\TestCase;

final class MarketplaceFinancialReportSyncStatusTest extends TestCase
{
    public function testMarkFailedRetryableResetsRecordsCount(): void
    {
        $status = $this->statusEntity();
        $status->markRawLoaded($this->rawId(), 100, 'rows-hash');

        $status->markFailedRetryable('HttpException', 'too many requests', 429, null, null);

        self::assertSame(0, $status->getRecordsCount());
    }

    public function testMarkLoadingResetsCurrentAttemptResultFields(): void
    {
        $status = $this->statusEntity();
        $status->markRawLoaded($this->rawId(), 100, 'rows-hash');

        $status->markLoading(FinancialReportSyncMode::DAILY);

        self::assertSame(0, $status->getRecordsCount());
        self::assertNull($status->getRawDocumentId());
        self::assertNull($status->getRowsHash());
    }

    public function testMarkRawLoadedSetsProvidedRecordsCount(): void
    {
        $status = $this->statusEntity();

        $status->markRawLoaded($this->rawId(), 50, 'rows-hash');

        self::assertSame(50, $status->getRecordsCount());
    }

    public function testMarkEmptySetsZeroRecordsCount(): void
    {
        $status = $this->statusEntity();
        $status->markRawLoaded($this->rawId(), 100, 'rows-hash');

        $status->markEmpty();

        self::assertSame(0, $status->getRecordsCount());
    }

    public function testMarkFailedFinalResetsRecordsCount(): void
    {
        $status = $this->statusEntity();
        $status->markRawLoaded($this->rawId(), 100, 'rows-hash');

        $status->markFailedFinal('ValidationException', 'bad payload', 422, null);

        self::assertSame(0, $status->getRecordsCount());
    }

    public function testMarkAuthFailedResetsRecordsCount(): void
    {
        $status = $this->statusEntity();
        $status->markRawLoaded($this->rawId(), 100, 'rows-hash');

        $status->markAuthFailed('AuthException', 'token expired', 401, null);

        self::assertSame(0, $status->getRecordsCount());
    }

    public function testMarkConflictResetsRecordsCount(): void
    {
        $status = $this->statusEntity();
        $status->markRawLoaded($this->rawId(), 100, 'rows-hash');

        $status->markConflict('ConflictException', 'conflict detected', 409, null);

        self::assertSame(0, $status->getRecordsCount());
    }

    private function statusEntity(): MarketplaceFinancialReportSyncStatus
    {
        return new MarketplaceFinancialReportSyncStatus(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            '33333333-3333-4333-8333-333333333333',
            MarketplaceType::WILDBERRIES,
            'sales_report',
            '/api/report',
            new \DateTimeImmutable('2026-05-20'),
        );
    }

    private function rawId(): string
    {
        return '44444444-4444-4444-8444-444444444444';
    }
}
