<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics\Infrastructure\Cache;

use App\Analytics\Infrastructure\Cache\InvalidateSnapshotOnCashChangeListener;
use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Cash\Domain\Event\CashTransactionsChanged;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class InvalidateSnapshotOnCashChangeListenerTest extends TestCase
{
    public function testBumpsSnapshotVersionOfChangedCompanyOnly(): void
    {
        $invalidator = new SnapshotCacheInvalidator(new ArrayAdapter());
        $changed = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111111111')->build();
        $other = CompanyBuilder::aCompany()->withId('22222222-2222-2222-2222-222222222222')->build();
        $changedBefore = $invalidator->resolveVersionForCompany($changed);
        $otherBefore = $invalidator->resolveVersionForCompany($other);

        (new InvalidateSnapshotOnCashChangeListener($invalidator))(
            new CashTransactionsChanged('11111111-1111-1111-1111-111111111111'),
        );

        self::assertSame($changedBefore + 1, $invalidator->resolveVersionForCompany($changed));
        self::assertSame($otherBefore, $invalidator->resolveVersionForCompany($other));
    }
}
