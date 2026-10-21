<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

/**
 * Похожи ли названия двух статей ОПиУ настолько, что вторая статья с таким названием — дубль.
 *
 * Похожи: одинаковые после нормализации (регистр, «ё», знаки препинания, пробелы) либо одно
 * совпадает с началом другого до разделителя — «/», «(», «-», «,», «:», «;», «|»: «Налог на
 * прибыль» и «Налог на прибыль / УСН» или «Налог на прибыль (доходы)». Продолжение словами не
 * считается: «Хранение» и «Хранение на ФФ», «Расходы» и «Расходы ниже EBITDA» — разные статьи.
 * Короткие названия (меньше MIN_PREFIX_LENGTH символов) сравниваются только целиком.
 */
final readonly class PLCategoryNameSimilarity
{
    private const MIN_PREFIX_LENGTH = 6;
    private const SEPARATORS = '/(\-–—,:;|';

    public function similar(string $a, string $b): bool
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);
        if ('' === $na || '' === $nb) {
            return false;
        }
        if ($na === $nb) {
            return true;
        }

        return $this->headEquals($a, $nb) || $this->headEquals($b, $na);
    }

    public static function normalize(string $name): string
    {
        $name = str_replace('ё', 'е', mb_strtolower($name));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name));
    }

    /**
     * Название до первого разделителя совпадает с другим (уже нормализованным) названием.
     */
    private function headEquals(string $name, string $other): bool
    {
        $parts = preg_split('#['.self::SEPARATORS.']#u', $name, 2);
        if (false === $parts || \count($parts) < 2) {
            return false;
        }
        $head = self::normalize($parts[0]);

        return mb_strlen($head) >= self::MIN_PREFIX_LENGTH && $head === $other;
    }
}
