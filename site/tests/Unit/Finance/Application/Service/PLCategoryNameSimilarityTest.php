<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Application\Service;

use App\Finance\Application\Service\PLCategoryNameSimilarity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PLCategoryNameSimilarityTest extends TestCase
{
    #[DataProvider('pairs')]
    public function testSimilar(string $a, string $b, bool $expected): void
    {
        $similarity = new PLCategoryNameSimilarity();

        self::assertSame($expected, $similarity->similar($a, $b));
        self::assertSame($expected, $similarity->similar($b, $a), 'симметрично');
    }

    public function testSameIsOnlyExactMatchAfterNormalization(): void
    {
        $similarity = new PLCategoryNameSimilarity();

        self::assertTrue($similarity->same('Налог на ПРИБЫЛЬ', 'налог на прибыль'));
        self::assertFalse($similarity->same('Налог на прибыль', 'Налог на прибыль / УСН'));
        self::assertFalse($similarity->same('—', '—'));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function pairs(): iterable
    {
        yield 'одинаковые' => ['Амортизация', 'Амортизация', true];
        yield 'регистр, ё и пробелы' => ['  Налог на ПРИБЫЛЬ ', 'налог на прибыль', true];
        yield 'ё и е' => ['Расчёты', 'Расчеты', true];
        yield 'знаки препинания' => ['УСН / АУСН (Д−Р)', 'УСН АУСН Д Р', true];
        yield 'до разделителя «/»' => ['Налог на прибыль', 'Налог на прибыль / УСН', true];
        yield 'до скобки' => ['Налог на прибыль', 'Налог на прибыль (доходы)', true];
        yield 'до дефиса' => ['Прямые расходы', 'Прямые расходы - Шоурум', true];
        yield 'дефис внутри слова — не разделитель' => ['Аренда офис', 'Аренда офис-склад', false];
        yield 'тире с пробелами' => ['Прямые расходы', 'Прямые расходы — Шоурум', true];
        yield 'продолжение словами' => ['Расходы', 'Расходы ниже EBITDA', false];
        yield 'продолжение словами после короткого' => ['Хранение', 'Хранение на ФФ', false];
        yield 'начало посреди слова' => ['Налог на прибыль', 'Налог на прибыльность', false];
        yield 'короткое до разделителя не считается' => ['Склад', 'Склад / ФФ', false];
        yield 'короткие целиком' => ['Склад', 'склад', true];
        yield 'разные' => ['Амортизация', 'Дивиденды', false];
        yield 'общее слово не в начале' => ['Прибыль', 'Чистая прибыль', false];
        yield 'пустое' => ['', 'Амортизация', false];
        yield 'только знаки' => ['—', '—', false];
    }
}
