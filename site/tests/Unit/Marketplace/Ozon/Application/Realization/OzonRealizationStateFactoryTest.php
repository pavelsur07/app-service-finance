<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Application\Realization;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationReport;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationStateFactory;
use App\Marketplace\Ozon\Application\Reconciliation\ReconciliationMonth;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class OzonRealizationStateFactoryTest extends TestCase
{
    private OzonRealizationStateFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new OzonRealizationStateFactory();
    }

    public function testWaitingInsideWindowShowsAttempts(): void
    {
        $status = $this->pair();
        $status->markLoading(\App\Marketplace\Enum\FinancialReportSyncMode::POLL);
        $status->markEmpty();

        $state = $this->factory->create($this->month('2026-09'), false, $status, new \DateTimeImmutable('2026-10-03 10:00:00 Europe/Moscow'));

        self::assertSame('warning', $state->tone);
        self::assertStringContainsString('ожидается', $state->text);
        self::assertStringContainsString('попыток: 1', $state->text);
    }

    public function testBeforeWindowOpensAndAfterItCloses(): void
    {
        $before = $this->factory->create($this->month('2026-09'), false, null, new \DateTimeImmutable('2026-10-01 12:00:00 Europe/Moscow'));
        self::assertSame('secondary', $before->tone);
        self::assertStringContainsString('1-го числа в 18:00', $before->text);

        $after = $this->factory->create($this->month('2026-09'), false, null, new \DateTimeImmutable('2026-10-09 00:30:00 Europe/Moscow'));
        self::assertSame('warning', $after->tone);
        self::assertStringContainsString('вручную', $after->text);
    }

    public function testCurrentAndFutureMonthsWaitForMonthEnd(): void
    {
        $state = $this->factory->create($this->month('2026-10'), false, null, new \DateTimeImmutable('2026-10-03 10:00:00 Europe/Moscow'));

        self::assertSame('secondary', $state->tone);
        self::assertStringContainsString('после закрытия месяца', $state->text);
    }

    public function testOlderMonthWithoutReportAsksForManualLoad(): void
    {
        $state = $this->factory->create($this->month('2026-07'), false, null, new \DateTimeImmutable('2026-10-03 10:00:00 Europe/Moscow'));

        self::assertSame('warning', $state->tone);
        self::assertStringContainsString('вручную', $state->text);
    }

    public function testRejectedKeyIsDanger(): void
    {
        $status = $this->pair();
        $status->markAuthFailed('X', 'rejected', 403, null);

        $state = $this->factory->create($this->month('2026-09'), false, $status, new \DateTimeImmutable('2026-10-03 10:00:00 Europe/Moscow'));

        self::assertSame('danger', $state->tone);
        self::assertStringContainsString('ключ', $state->text);
    }

    public function testLoadedStates(): void
    {
        $now = new \DateTimeImmutable('2026-10-03 10:00:00 Europe/Moscow');

        self::assertSame('загружена', $this->factory->create($this->month('2026-09'), true, null, $now)->text);

        $success = $this->pair();
        $success->markSuccess();
        $state = $this->factory->create($this->month('2026-09'), true, $success, $now);
        self::assertSame('success', $state->tone);
        self::assertStringContainsString('применена в учёт', $state->text);

        $conflict = $this->pair();
        $conflict->markConflict('MonthStageClosed', 'closed', null, null);
        $state = $this->factory->create($this->month('2026-09'), true, $conflict, $now);
        self::assertSame('warning', $state->tone);
        self::assertStringContainsString('закрыт', $state->text);

        $processing = $this->pair();
        $processing->markProcessing();
        self::assertStringContainsString('применяется', $this->factory->create($this->month('2026-09'), true, $processing, $now)->text);

        $final = $this->pair();
        $final->markFailedFinal('E', 'boom', null, null);
        $state = $this->factory->create($this->month('2026-09'), true, $final, $now);
        self::assertSame('danger', $state->tone);
        self::assertStringContainsString('остановлена', $state->text);

        $failed = $this->pair();
        $failed->markFailedRetryable('E', 'boom', null, null, new \DateTimeImmutable('2026-10-03 11:00:00 Europe/Moscow'));
        $state = $this->factory->create($this->month('2026-09'), true, $failed, $now);
        self::assertSame('warning', $state->tone);
        self::assertStringContainsString('повтор в 03.10 11:00', $state->text);
    }

    private function month(string $value): ReconciliationMonth
    {
        $month = ReconciliationMonth::tryParse($value);
        self::assertNotNull($month);

        return $month;
    }

    private function pair(): MarketplaceFinancialReportSyncStatus
    {
        return new MarketplaceFinancialReportSyncStatus(
            Uuid::uuid4()->toString(),
            Uuid::uuid4()->toString(),
            Uuid::uuid4()->toString(),
            MarketplaceType::OZON,
            OzonRealizationReport::REPORT_TYPE,
            OzonRealizationReport::apiEndpoint(),
            new \DateTimeImmutable('2026-09-01'),
        );
    }
}
