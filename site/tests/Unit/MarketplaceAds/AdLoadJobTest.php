<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use PHPUnit\Framework\TestCase;

final class AdLoadJobTest extends TestCase
{
    public function testConstructorAutoComputesTotalDaysInclusive(): void
    {
        $job = new AdLoadJob(
            companyId: AdLoadJobBuilder::DEFAULT_COMPANY_ID,
            marketplace: MarketplaceType::OZON,
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-10'),
        );

        // 10 дней включительно
        self::assertSame(10, $job->getTotalDays());
    }

    public function testConstructorSingleDayRangeIsOneDay(): void
    {
        $job = new AdLoadJob(
            companyId: AdLoadJobBuilder::DEFAULT_COMPANY_ID,
            marketplace: MarketplaceType::OZON,
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-01'),
        );

        self::assertSame(1, $job->getTotalDays());
    }

    public function testConstructorNormalizesDateTimesToMidnight(): void
    {
        // Передаём одну и ту же дату с разным временем в обратном порядке часов —
        // без нормализации getDate()->diff() выдал бы 0 дней, а проверка `$dateFrom > $dateTo`
        // вообще упала бы на DomainException (14:30 > 09:15).
        $job = new AdLoadJob(
            companyId: AdLoadJobBuilder::DEFAULT_COMPANY_ID,
            marketplace: MarketplaceType::OZON,
            dateFrom: new \DateTimeImmutable('2026-03-01 14:30:45'),
            dateTo: new \DateTimeImmutable('2026-03-01 09:15:00'),
        );

        self::assertSame('2026-03-01 00:00:00', $job->getDateFrom()->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-01 00:00:00', $job->getDateTo()->format('Y-m-d H:i:s'));
        self::assertSame(1, $job->getTotalDays());
    }

    public function testConstructorNormalizationProducesCorrectTotalDaysAcrossMultiDayRange(): void
    {
        // 10 дней включительно, но с «грязным» временем — totalDays не должен сбиться.
        $job = new AdLoadJob(
            companyId: AdLoadJobBuilder::DEFAULT_COMPANY_ID,
            marketplace: MarketplaceType::OZON,
            dateFrom: new \DateTimeImmutable('2026-03-01 23:59:59'),
            dateTo: new \DateTimeImmutable('2026-03-10 00:00:01'),
        );

        self::assertSame(10, $job->getTotalDays());
        self::assertSame('00:00:00', $job->getDateFrom()->format('H:i:s'));
        self::assertSame('00:00:00', $job->getDateTo()->format('H:i:s'));
    }

    public function testConstructorRejectsInvertedRange(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('dateFrom не может быть позже dateTo');

        new AdLoadJob(
            companyId: AdLoadJobBuilder::DEFAULT_COMPANY_ID,
            marketplace: MarketplaceType::OZON,
            dateFrom: new \DateTimeImmutable('2026-03-10'),
            dateTo: new \DateTimeImmutable('2026-03-01'),
        );
    }

    public function testNewJobHasPendingStatusAndZeroCounters(): void
    {
        $job = AdLoadJobBuilder::aJob()->build();

        self::assertSame(AdLoadJobStatus::PENDING, $job->getStatus());
        self::assertSame(0, $job->getLoadedDays());
        self::assertSame(0, $job->getProcessedDays());
        self::assertSame(0, $job->getFailedDays());
        self::assertNull($job->getStartedAt());
        self::assertNull($job->getFinishedAt());
        self::assertNull($job->getFailureReason());
    }

    public function testMarkRunningFromPending(): void
    {
        $job = AdLoadJobBuilder::aJob()->build();

        $job->markRunning();

        self::assertSame(AdLoadJobStatus::RUNNING, $job->getStatus());
        self::assertNotNull($job->getStartedAt());
        self::assertNull($job->getFinishedAt());
    }

    public function testMarkRunningFromRunningThrows(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Запустить можно только задание в статусе PENDING');

        $job->markRunning();
    }

    public function testMarkCompletedSetsFinishedAt(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $job->markCompleted();

        self::assertSame(AdLoadJobStatus::COMPLETED, $job->getStatus());
        self::assertNotNull($job->getFinishedAt());
    }

    public function testMarkCompletedOnTerminalThrows(): void
    {
        $job = AdLoadJobBuilder::aJob()->asCompleted()->build();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Нельзя завершить задание в терминальном статусе');

        $job->markCompleted();
    }

    public function testMarkFailedSetsReasonAndFinishedAt(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $job->markFailed('API Ozon вернул 500');

        self::assertSame(AdLoadJobStatus::FAILED, $job->getStatus());
        self::assertSame('API Ozon вернул 500', $job->getFailureReason());
        self::assertNotNull($job->getFinishedAt());
    }

    public function testMarkFailedRejectsEmptyReason(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $this->expectException(\InvalidArgumentException::class);

        $job->markFailed('');
    }

    public function testMarkFailedOnTerminalThrows(): void
    {
        $job = AdLoadJobBuilder::aJob()->asFailed()->build();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Нельзя пометить неуспешным задание в терминальном статусе');

        $job->markFailed('вторая попытка');
    }

    public function testGetProgressComputesPercentFromLoadedPlusFailed(): void
    {
        // 10 дней, 3 loaded + 2 failed = 50%
        $job = AdLoadJobBuilder::aJob()
            ->withLoaded(3)
            ->withFailed(2)
            ->build();

        self::assertSame(10, $job->getTotalDays());
        self::assertSame(50, $job->getProgress());
    }

    public function testGetProgressCapsAt100(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withLoaded(15) // больше totalDays=10
            ->build();

        self::assertSame(100, $job->getProgress());
    }

    public function testGetProgressFloorsPartialPercent(): void
    {
        // 10 дней, 1 loaded = 10%
        $job = AdLoadJobBuilder::aJob()
            ->withLoaded(1)
            ->build();

        self::assertSame(10, $job->getProgress());
    }

    public function testNewJobHasZeroChunksTotalAndCompleted(): void
    {
        $job = AdLoadJobBuilder::aJob()->build();

        self::assertSame(0, $job->getChunksTotal());
        self::assertSame(0, $job->getChunksCompleted());
    }

    public function testSetChunksTotalFromPendingPersistsValue(): void
    {
        $job = AdLoadJobBuilder::aJob()->build();
        self::assertSame(AdLoadJobStatus::PENDING, $job->getStatus());

        $job->setChunksTotal(7);

        self::assertSame(7, $job->getChunksTotal());
    }

    public function testSetChunksTotalFromRunningPersistsValue(): void
    {
        // Retry-сценарий: job уже RUNNING, chunksTotal ещё можно поставить
        // (в продакшн-коде это не произойдёт — handler проверяет chunksTotal === 0,
        // но guard Entity должен позволять установку из активных статусов).
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $job->setChunksTotal(3);

        self::assertSame(3, $job->getChunksTotal());
    }

    public function testSetChunksTotalOnCompletedThrows(): void
    {
        $job = AdLoadJobBuilder::aJob()->asCompleted()->build();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Нельзя установить chunksTotal на задание в терминальном статусе');

        $job->setChunksTotal(5);
    }

    public function testSetChunksTotalOnFailedThrows(): void
    {
        $job = AdLoadJobBuilder::aJob()->asFailed('test')->build();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Нельзя установить chunksTotal на задание в терминальном статусе');

        $job->setChunksTotal(5);
    }

    /**
     * @dataProvider invalidChunksTotalProvider
     */
    public function testSetChunksTotalRejectsZeroOrNegative(int $total): void
    {
        $job = AdLoadJobBuilder::aJob()->build();

        $this->expectException(\InvalidArgumentException::class);

        $job->setChunksTotal($total);
    }

    public static function invalidChunksTotalProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'large negative' => [-100],
        ];
    }
}
