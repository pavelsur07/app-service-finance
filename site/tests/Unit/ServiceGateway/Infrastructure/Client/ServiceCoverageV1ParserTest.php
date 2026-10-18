<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Infrastructure\Client\ServiceCoverageV1Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServiceCoverageV1ParserTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    public static function fixture(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../../../Fixtures/ServiceGateway/service-coverage-v1.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /* @var array<string, mixed> $data */
        return $data;
    }

    public function testParsesContractExample(): void
    {
        $report = (new ServiceCoverageV1Parser())->parse(self::fixture());

        self::assertSame('ozon-fetcher', $report->service);
        self::assertSame('2026-08-01', $report->periodFrom->format('Y-m-d'));
        self::assertSame('2026-10-31', $report->periodTo->format('Y-m-d'));
        self::assertSame(4, $report->cabinetsTotal);
        self::assertCount(3, $report->rows);

        $byDay = $report->rows[0];
        self::assertSame('ozon.finance.accrual-by-day', $byDay->resourceType);
        self::assertSame('2026-10-17', $byDay->periodKey);
        self::assertSame(3, $byDay->byStatus->done);
        self::assertSame(1, $byDay->byStatus->running);
        self::assertSame(4, $byDay->byStatus->total());
        self::assertNotNull($byDay->lastSuccessAt);
        self::assertSame(
            (new \DateTimeImmutable('2026-10-17T21:05:12Z'))->getTimestamp(),
            $byDay->lastSuccessAt->getTimestamp(),
        );
        self::assertSame(date_default_timezone_get(), $byDay->lastSuccessAt->getTimezone()->getName(), 'время — в поясе приложения');

        self::assertSame('2026-09', $report->rows[1]->periodKey);
        self::assertSame('ref', $report->rows[2]->periodKey);
        self::assertSame(1, $report->rows[2]->byStatus->failedPermanent);
        self::assertNull($report->rows[2]->lastSuccessAt);
    }

    public function testParsesEmptyRows(): void
    {
        $data = self::fixture();
        $data['rows'] = [];

        self::assertSame([], (new ServiceCoverageV1Parser())->parse($data)->rows);
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): mixed, string}>
     */
    public static function violations(): iterable
    {
        yield 'not an object' => [static fn (array $d): mixed => [1, 2], '$'];
        yield 'extra top key' => [static fn (array $d): mixed => $d + ['connection_ref' => 'x'], '$'];
        yield 'missing top key' => [static function (array $d): mixed {
            unset($d['cabinets_total']);

            return $d;
        }, '$'];
        yield 'wrong schema version' => [static fn (array $d): mixed => ['schema_version' => '2'] + $d, '$.schema_version'];
        yield 'integer schema version' => [static fn (array $d): mixed => ['schema_version' => 1] + $d, '$.schema_version'];
        yield 'bad date' => [static fn (array $d): mixed => ['from' => '2026-8-01'] + $d, '$.from'];
        yield 'bad generated_at' => [static fn (array $d): mixed => ['generated_at' => '2026-10-18 06:00'] + $d, '$.generated_at'];
        yield 'negative total' => [static fn (array $d): mixed => ['cabinets_total' => -1] + $d, '$.cabinets_total'];
        yield 'float total' => [static fn (array $d): mixed => ['cabinets_total' => 4.0] + $d, '$.cabinets_total'];
        yield 'rows not list' => [static fn (array $d): mixed => ['rows' => ['a' => 1]] + $d, '$.rows'];
        yield 'bad period key' => [static fn (array $d): mixed => self::withRow($d, ['period_key' => '2026-10-17T00']), '$.rows[0].period_key'];
        yield 'empty resource' => [static fn (array $d): mixed => self::withRow($d, ['resource_type' => '']), '$.rows[0].resource_type'];
        yield 'extra row key' => [static fn (array $d): mixed => self::withRow($d, ['company_id' => 'x']), '$.rows[0]'];
        yield 'missing status' => [static fn (array $d): mixed => self::withRow($d, ['by_status' => ['done' => 1]]), '$.rows[0].by_status'];
        yield 'negative status' => [static fn (array $d): mixed => self::withRow($d, ['by_status' => ['done' => -1, 'running' => 0, 'pending' => 0, 'not_ready' => 0, 'failed_retryable' => 0, 'failed_permanent' => 0]]), '$.rows[0].by_status.done'];
        yield 'bad last success' => [static fn (array $d): mixed => self::withRow($d, ['last_success_at' => 'yesterday']), '$.rows[0].last_success_at'];
        yield 'duplicate row' => [static function (array $d): mixed {
            /** @var list<mixed> $rows */
            $rows = $d['rows'];
            $d['rows'] = [$rows[0], $rows[0]];

            return $d;
        }, '$.rows[1]'];
    }

    /**
     * @param \Closure(array<string, mixed>): mixed $mutate
     */
    #[DataProvider('violations')]
    public function testRejectsContractViolation(\Closure $mutate, string $path): void
    {
        $this->expectException(ContractViolationException::class);
        $this->expectExceptionMessage('service-coverage/v1 violated at '.$path.':');

        (new ServiceCoverageV1Parser())->parse($mutate(self::fixture()));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    private static function withRow(array $data, array $patch): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $data['rows'];
        $rows[0] = $patch + $rows[0];
        $data['rows'] = $rows;

        return $data;
    }
}
