<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Controller\Api\BalanceDynamics;

use App\Cash\Enum\FiatCurrency;
use App\Finance\Controller\Api\BalanceDynamics\BalanceDynamicsRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BalanceDynamicsRequestTest extends TestCase
{
    public function testUsesThirtyDaysAndRubByDefault(): void
    {
        $query = BalanceDynamicsRequest::fromRequest(Request::create('/'));

        self::assertSame(30, $query->periodDays);
        self::assertSame(FiatCurrency::RUB, $query->currency);
    }

    public function testParsesAllowedPeriodAndCurrency(): void
    {
        $query = BalanceDynamicsRequest::fromRequest(Request::create('/?period=60&currency=usd'));

        self::assertSame(60, $query->periodDays);
        self::assertSame(FiatCurrency::USD, $query->currency);
    }

    #[DataProvider('invalidQueryProvider')]
    public function testRejectsInvalidQuery(array $query): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BalanceDynamicsRequest::fromRequest(Request::create('/', parameters: $query));
    }

    public static function invalidQueryProvider(): iterable
    {
        yield 'unsupported period' => [['period' => '14']];
        yield 'non scalar period' => [['period' => ['30']]];
        yield 'unsupported currency' => [['currency' => 'BTC']];
        yield 'non scalar currency' => [['currency' => ['RUB']]];
    }
}
