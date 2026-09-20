<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Entity;

use App\MoySklad\Domain\CounterpartySnapshot;
use App\MoySklad\Entity\MoySkladCounterparty;
use PHPUnit\Framework\TestCase;

final class MoySkladCounterpartyTest extends TestCase
{
    private const COMPANY = '11111111-1111-4111-8111-111111111111';
    private const CONNECTION = '22222222-2222-4222-8222-222222222222';
    private const EXTERNAL = '33333333-3333-4333-8333-333333333333';

    public function testRepeatedSnapshotUpdatesLoadTimeWithoutChangingIdentity(): void
    {
        $firstLoad = new \DateTimeImmutable('2026-09-20T08:00:00+00:00');
        $secondLoad = $firstLoad->modify('+1 hour');
        $record = new MoySkladCounterparty(
            '44444444-4444-7444-8444-444444444444',
            self::COMPANY,
            self::CONNECTION,
            $this->snapshot(),
            $firstLoad,
        );

        self::assertFalse($record->applySnapshot($this->snapshot(), $secondLoad));
        self::assertSame(self::COMPANY, $record->getCompanyId());
        self::assertSame(self::CONNECTION, $record->getConnectionId());
        self::assertSame(self::EXTERNAL, $record->getExternalId());
        self::assertSame($secondLoad, $record->getLoadedAt());
        self::assertSame('ООО Тест', $record->getName());
    }

    public function testNewSnapshotReplacesOptionalFieldsAndArchivedFlag(): void
    {
        $record = new MoySkladCounterparty(
            '44444444-4444-7444-8444-444444444444',
            self::COMPANY,
            self::CONNECTION,
            $this->snapshot(),
            new \DateTimeImmutable('2026-09-20T08:00:00+00:00'),
        );
        $changed = new CounterpartySnapshot(
            self::EXTERNAL,
            'ООО Новое имя',
            'legal',
            null,
            null,
            null,
            null,
            null,
            null,
            true,
            new \DateTimeImmutable('2026-09-20T08:30:00+00:00'),
        );

        self::assertTrue($record->applySnapshot($changed, new \DateTimeImmutable('2026-09-20T09:00:00+00:00')));
        self::assertSame('ООО Новое имя', $record->getName());
        self::assertNull($record->getInn());
        self::assertTrue($record->isArchived());
    }

    private function snapshot(): CounterpartySnapshot
    {
        return new CounterpartySnapshot(
            self::EXTERNAL,
            'ООО Тест',
            'legal',
            'ООО Тест',
            '9999999991',
            '999999991',
            '9999999999991',
            null,
            'Тестовый адрес',
            false,
            new \DateTimeImmutable('2026-09-20T07:00:00+00:00'),
        );
    }
}
