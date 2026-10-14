<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Facade;

use App\Company\Facade\DTO\CounterpartyChoiceDTO;
use PHPUnit\Framework\TestCase;

final class CounterpartyChoiceDTOTest extends TestCase
{
    public function testLabelUsesDisplayNameWhenSet(): void
    {
        $choice = new CounterpartyChoiceDTO('id', 'ООО "Ромашка"', '7707083893', null, true, 'ООО «Ромашка»');

        self::assertSame('ООО «Ромашка»', $choice->shownName());
        self::assertSame('ООО «Ромашка» · 7707083893 (в архиве)', $choice->label());
    }

    public function testLabelFallsBackToRawName(): void
    {
        $choice = new CounterpartyChoiceDTO('id', 'ООО "Ромашка"', null, null, false);

        self::assertSame('ООО "Ромашка"', $choice->shownName());
        self::assertSame('ООО "Ромашка"', $choice->label());
    }
}
