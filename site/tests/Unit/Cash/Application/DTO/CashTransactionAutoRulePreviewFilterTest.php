<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application\DTO;

use App\Cash\Application\DTO\CashTransactionAutoRulePreviewFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CashTransactionAutoRulePreviewFilterTest extends TestCase
{
    #[DataProvider('validFilterProvider')]
    public function testCreatesValidatedFilter(string $dateFrom, string $dateTo, string $limit, int $expectedLimit): void
    {
        $filter = CashTransactionAutoRulePreviewFilter::fromStrings($dateFrom, $dateTo, $limit);

        self::assertSame($dateFrom, $filter->dateFrom->format('Y-m-d'));
        self::assertSame($dateTo, $filter->dateTo->format('Y-m-d'));
        self::assertSame($expectedLimit, $filter->limit);
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function validFilterProvider(): iterable
    {
        yield 'minimum limit' => ['2025-01-01', '2025-01-01', '1', 10];
        yield 'maximum period' => ['2025-01-01', '2026-01-01', '500', 200];
    }

    #[DataProvider('invalidFilterProvider')]
    public function testRejectsInvalidFilter(string $dateFrom, string $dateTo, string $limit, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        CashTransactionAutoRulePreviewFilter::fromStrings($dateFrom, $dateTo, $limit);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidFilterProvider(): iterable
    {
        yield 'invalid date' => ['2025-02-30', '2025-03-01', '200', 'Укажите даты в формате ГГГГ-ММ-ДД.'];
        yield 'reversed range' => ['2025-03-02', '2025-03-01', '200', 'Начальная дата не может быть позже конечной.'];
        yield 'more than 366 days' => ['2024-01-01', '2025-01-01', '200', 'Период проверки не может превышать 366 дней.'];
        yield 'invalid limit' => ['2025-01-01', '2025-01-31', 'all', 'Лимит должен быть целым положительным числом.'];
    }
}
