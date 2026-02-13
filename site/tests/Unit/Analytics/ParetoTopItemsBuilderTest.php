<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\Application\Widget\ParetoTopItemsBuilder;
use PHPUnit\Framework\TestCase;

final class ParetoTopItemsBuilderTest extends TestCase
{
    public function testSplitsItemsByCoverageAndMaxItems(): void
    {
        $builder = new ParetoTopItemsBuilder();

        $result = $builder->split([
            ['category_id' => '1', 'sum' => 60.0],
            ['category_id' => '2', 'sum' => 30.0],
            ['category_id' => '3', 'sum' => 10.0],
        ], 100.0, 0.8, 8);

        self::assertCount(2, $result['items']);
        self::assertSame('1', $result['items'][0]['category_id']);
        self::assertSame('2', $result['items'][1]['category_id']);
        self::assertSame(10.0, $result['other']['sum']);
        self::assertSame(0.1, $result['other']['share']);
    }
}
