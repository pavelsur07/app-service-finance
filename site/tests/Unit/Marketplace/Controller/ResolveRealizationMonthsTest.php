<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Controller;

use App\Marketplace\Controller\MarketplaceController;
use App\Marketplace\Infrastructure\Query\OzonRealizationStatusQuery;
use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\TestCase;

final class ResolveRealizationMonthsTest extends TestCase
{
    private \ReflectionMethod $method;
    private MarketplaceController $controller;
    private DbalConnection $dbalConnection;

    protected function setUp(): void
    {
        $this->dbalConnection = $this->createMock(DbalConnection::class);
        $statusQuery = new OzonRealizationStatusQuery($this->dbalConnection);

        $ref = new \ReflectionClass(MarketplaceController::class);
        $this->controller = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('realizationStatusQuery');
        $prop->setValue($this->controller, $statusQuery);

        $this->method = $ref->getMethod('resolveRealizationMonths');
    }

    private function invoke(string $companyId, \DateTimeImmutable $now): array
    {
        return $this->method->invoke($this->controller, $companyId, $now);
    }

    private function stubLoadedMonths(array $months): void
    {
        $rows = array_map(static fn(string $m) => ['month' => $m], $months);

        $this->dbalConnection
            ->method('fetchAllAssociative')
            ->willReturn($rows);
    }

    public function testAllMonthsMissingReturnsJanToMarch(): void
    {
        $this->stubLoadedMonths([]);

        $now = new \DateTimeImmutable('2026-04-17', new \DateTimeZone('Europe/Moscow'));

        self::assertSame([[2026, 1], [2026, 2], [2026, 3]], $this->invoke('company-1', $now));
    }

    public function testPartiallyLoadedSkipsExisting(): void
    {
        $this->stubLoadedMonths(['2026-02']);

        $now = new \DateTimeImmutable('2026-04-17', new \DateTimeZone('Europe/Moscow'));

        self::assertSame([[2026, 1], [2026, 3]], $this->invoke('company-1', $now));
    }

    public function testAllLoadedReturnsEmpty(): void
    {
        $this->stubLoadedMonths(['2026-01', '2026-02', '2026-03']);

        $now = new \DateTimeImmutable('2026-04-17', new \DateTimeZone('Europe/Moscow'));

        self::assertSame([], $this->invoke('company-1', $now));
    }

    public function testJanuaryReturnsEmptyBecauseNoClosedMonths(): void
    {
        $this->stubLoadedMonths([]);

        $now = new \DateTimeImmutable('2026-01-10', new \DateTimeZone('Europe/Moscow'));

        self::assertSame([], $this->invoke('company-1', $now));
    }

    public function testFebruaryReturnsOnlyJanuary(): void
    {
        $this->stubLoadedMonths([]);

        $now = new \DateTimeImmutable('2026-02-15', new \DateTimeZone('Europe/Moscow'));

        self::assertSame([[2026, 1]], $this->invoke('company-1', $now));
    }

    public function testDecemberReturnsAllElevenMonths(): void
    {
        $this->stubLoadedMonths([]);

        $now = new \DateTimeImmutable('2026-12-05', new \DateTimeZone('Europe/Moscow'));

        $expected = [];
        for ($m = 1; $m <= 11; $m++) {
            $expected[] = [2026, $m];
        }

        self::assertSame($expected, $this->invoke('company-1', $now));
    }

    public function testRepeatedClickStillReturnsMissingMonths(): void
    {
        $this->stubLoadedMonths(['2026-01', '2026-02']);

        $now = new \DateTimeImmutable('2026-04-17', new \DateTimeZone('Europe/Moscow'));

        self::assertSame([[2026, 3]], $this->invoke('company-1', $now));
    }
}
