<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\DTO\Internal\ActiveConnectionCredentials;
use App\Marketplace\Application\Service\WbConnectionsSnapshotBuilder;
use App\Marketplace\Enum\MarketplaceConnectionType;
use PHPUnit\Framework\TestCase;

final class WbConnectionsSnapshotBuilderTest extends TestCase
{
    private const COMPANY_A = '11111111-1111-7111-8111-111111111111';
    private const COMPANY_B = '22222222-2222-7222-8222-222222222222';
    private const WB_A = 'aaaaaaaa-0000-7000-8000-00000000000a';
    private const WB_B = 'bbbbbbbb-0000-7000-8000-00000000000b';

    public function testConnectionWithTokenBecomesCabinetKeyedByConnectionId(): void
    {
        $cabinet = $this->build([$this->wb(self::WB_A, self::COMPANY_A, updatedAt: '2026-10-05 10:00:00')], [self::COMPANY_A])['connections'][0];

        self::assertSame(self::WB_A, $cabinet['connection_ref']);
        self::assertSame(self::COMPANY_A, $cabinet['company_id']);
        self::assertSame('wildberries', $cabinet['provider']);
        // client_id — единица лимита WB: та же, что бакет Symfony `connection:<id>`.
        self::assertSame(['client_id' => self::WB_A, 'api_key' => 'wb-token'], $cabinet['credentials']['seller']);
        self::assertNull($cabinet['credentials']['performance']);
        self::assertSame(WbConnectionsSnapshotBuilder::FINANCE_RESOURCE_TYPES, $cabinet['resource_types']);
        self::assertSame(['wb.finance.sales-report.daily', 'wb.finance.sales-report.weekly'], $cabinet['resource_types']);
        self::assertSame((new \DateTimeImmutable('2026-10-05 10:00:00'))->format(\DateTimeInterface::ATOM), $cabinet['updated_at']);
    }

    public function testFinanceResourcesOnlyForListedCompanies(): void
    {
        $rows = [$this->wb(self::WB_A, self::COMPANY_A), $this->wb(self::WB_B, self::COMPANY_B)];

        $listed = array_column($this->build($rows, [self::COMPANY_A])['connections'], 'resource_types', 'connection_ref');
        self::assertSame(WbConnectionsSnapshotBuilder::FINANCE_RESOURCE_TYPES, $listed[self::WB_A]);
        self::assertSame([], $listed[self::WB_B], 'кабинет вне списка выдаётся, но без ресурсов');

        foreach ($this->build($rows, ['*'])['connections'] as $cabinet) {
            self::assertSame(WbConnectionsSnapshotBuilder::FINANCE_RESOURCE_TYPES, $cabinet['resource_types']);
        }
        foreach ($this->build($rows, [])['connections'] as $cabinet) {
            self::assertSame([], $cabinet['resource_types']);
        }
    }

    public function testConnectionWithoutUsableTokenIsNotServed(): void
    {
        $snapshot = $this->build([
            $this->wb(self::WB_A, self::COMPANY_A, secret: null),
            $this->wb(self::WB_B, self::COMPANY_B, secret: '  '),
        ], ['*']);

        self::assertSame([], $snapshot['connections']);
    }

    public function testPerformanceRowsAreIgnored(): void
    {
        $snapshot = $this->build([
            new ActiveConnectionCredentials(self::WB_A, self::COMPANY_A, MarketplaceConnectionType::PERFORMANCE, 'c', 's', new \DateTimeImmutable('2026-10-01')),
        ], ['*']);

        self::assertSame([], $snapshot['connections']);
    }

    public function testCabinetsAreOrderedByRefAndEnvelopeFollowsContract(): void
    {
        $snapshot = $this->build([$this->wb(self::WB_B, self::COMPANY_B), $this->wb(self::WB_A, self::COMPANY_A)], []);

        self::assertSame('1', $snapshot['schema_version']);
        self::assertSame('2026-11-02T12:00:00+00:00', $snapshot['generated_at']);
        self::assertSame([self::WB_A, self::WB_B], array_column($snapshot['connections'], 'connection_ref'));
    }

    /**
     * @param list<ActiveConnectionCredentials> $rows
     * @param list<string> $financeCompanyIds
     *
     * @return array<string, mixed>
     */
    private function build(array $rows, array $financeCompanyIds): array
    {
        return (new WbConnectionsSnapshotBuilder($financeCompanyIds))->build($rows, new \DateTimeImmutable('2026-11-02 12:00:00 UTC'))->toArray();
    }

    private function wb(string $id, string $companyId, ?string $secret = 'wb-token', string $updatedAt = '2026-10-01 10:00:00'): ActiveConnectionCredentials
    {
        return new ActiveConnectionCredentials($id, $companyId, MarketplaceConnectionType::SELLER, null, $secret, new \DateTimeImmutable($updatedAt));
    }
}
