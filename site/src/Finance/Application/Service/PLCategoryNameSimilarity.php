<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

/**
 * Похожи ли названия двух статей ОПиУ настолько, что вторая статья с таким названием — дубль.
 *
 * Похожи: одинаковые после нормализации (регистр, «ё», знаки препинания, пробелы) либо одно —
 * начало другого по границе слова («Налог на прибыль» и «Налог на прибыль / УСН»). Короткие
 * названия (меньше MIN_PREFIX_LENGTH символов) сравниваются только целиком: «Прочие» не должно
 * совпадать со всеми «Прочими …».
 */
final readonly class PLCategoryNameSimilarity
{
    private const MIN_PREFIX_LENGTH = 6;

    public function similar(string $a, string $b): bool
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if ('' === $a || '' === $b) {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];

        return mb_strlen($short) >= self::MIN_PREFIX_LENGTH && str_starts_with($long, $short.' ');
    }

    public static function normalize(string $name): string
    {
        $name = str_replace('ё', 'е', mb_strtolower($name));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name));
    }
}
