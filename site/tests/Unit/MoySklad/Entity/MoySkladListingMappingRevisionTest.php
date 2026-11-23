<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Entity;

use App\MoySklad\Entity\MoySkladListingMappingRevision;
use App\MoySklad\Enum\ListingMappingOperation;
use App\Tests\Builders\MoySklad\MoySkladListingMappingRevisionBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoySkladListingMappingRevisionTest extends TestCase
{
    public function testSetKeepsIdentityTargetSnapshotAndExpectedRevision(): void
    {
        $revision = MoySkladListingMappingRevisionBuilder::aRevision()->withRevision(2)->build();
        self::assertSame(2, $revision->getRevision());
        self::assertSame(1, $revision->getExpectedRevision());
        self::assertSame('product', $revision->getTargetType());
        self::assertSame('44444444-4444-4444-8444-000000000001', $revision->getTargetExternalId());
        self::assertSame('Товар', $revision->getTargetSnapshot()['name'] ?? null);
        self::assertSame('001', $revision->getListingSnapshot()['sku']);
        self::assertSame('2026-11-15T00:00:00.123+00:00', $revision->getCreatedAt()->format('Y-m-d\\TH:i:s.vP'));
    }

    public function testClearIsAnExplicitRevisionWithoutTarget(): void
    {
        $clear = MoySkladListingMappingRevisionBuilder::aRevision()->withRevision(2)->clear()->build();
        self::assertSame('clear', $clear->getOperation()->value);
        self::assertNull($clear->getTargetType());
        self::assertNull($clear->getTargetExternalId());
        self::assertNull($clear->getTargetSnapshot());
        self::assertSame(2, $clear->getRevision());
    }

    public function testRejectsInvalidRevision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoySkladListingMappingRevisionBuilder::aRevision()->withRevision(0)->build();
    }

    public function testRejectsUnsupportedTargetType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoySkladListingMappingRevisionBuilder::aRevision()->withTarget('bundle', '44444444-4444-4444-8444-000000000001')->build();
    }

    public function testRejectsInvalidTenantUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoySkladListingMappingRevisionBuilder::aRevision()->withTenant('foreign', '33333333-3333-7333-8333-333333333333')->build();
    }

    #[DataProvider('wrappedUuids')]
    public function testRejectsWrappedUuidBeforeDatabaseWrite(string $companyId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoySkladListingMappingRevisionBuilder::aRevision()->withTenant($companyId, '33333333-3333-7333-8333-333333333333')->build();
    }

    /** @return iterable<string, array{string}> */
    public static function wrappedUuids(): iterable
    {
        yield 'URN' => ['urn:uuid:11111111-1111-7111-8111-111111111111'];
        yield 'braced' => ['{11111111-1111-7111-8111-111111111111}'];
    }

    /** @param array{expectedRevision?:int, listingSnapshot?:array<string,mixed>, targetSnapshot?:array<string,mixed>, operation?:ListingMappingOperation} $changes */
    #[DataProvider('inconsistentSnapshots')]
    public function testRejectsInconsistentConstructorArguments(array $changes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MoySkladListingMappingRevision(
            'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', '11111111-1111-7111-8111-111111111111',
            '33333333-3333-7333-8333-333333333333', '55555555-5555-4555-8555-000000000001',
            1, $changes['operation'] ?? ListingMappingOperation::SET, 'product', '44444444-4444-4444-8444-000000000001',
            '77777777-7777-4777-8777-000000000001', '22222222-2222-4222-8222-000000000001',
            $changes['expectedRevision'] ?? 0, new \DateTimeImmutable(),
            $changes['listingSnapshot'] ?? ['id' => '55555555-5555-4555-8555-000000000001'],
            $changes['targetSnapshot'] ?? ['type' => 'product', 'externalId' => '44444444-4444-4444-8444-000000000001'],
        );
    }

    /** @return iterable<string, array{array{expectedRevision?:int, listingSnapshot?:array<string,mixed>, targetSnapshot?:array<string,mixed>, operation?:ListingMappingOperation}}> */
    public static function inconsistentSnapshots(): iterable
    {
        yield 'revision gap' => [['expectedRevision' => 1]];
        yield 'listing identity' => [['listingSnapshot' => ['id' => '55555555-5555-4555-8555-000000000002']]];
        yield 'target type' => [['targetSnapshot' => ['type' => 'variant', 'externalId' => '44444444-4444-4444-8444-000000000001']]];
        yield 'target identity' => [['targetSnapshot' => ['type' => 'product', 'externalId' => '44444444-4444-4444-8444-000000000002']]];
        yield 'clear target' => [['operation' => ListingMappingOperation::CLEAR]];
    }
}
