<?php

declare(strict_types=1);

namespace Tests\Unit\Finance\Engine;

use App\Enum\PLValueFormat;
use App\Finance\Engine\ValueFormatter;
use PHPUnit\Framework\TestCase;

final class ValueFormatterTest extends TestCase
{
    public function testPercent(): void
    {
        $f = new ValueFormatter();
        $this->assertSame('60.0 %', $f->format(0.6, PLValueFormat::PERCENT));
    }
}
