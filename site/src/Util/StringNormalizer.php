<?php

namespace App\Util;

final class StringNormalizer
{
    private function __construct()
    {
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');

        return str_replace('ё', 'е', $value);
    }

    public static function contains(string $haystack, string $needle): bool
    {
        $needle = self::normalize($needle);

        if ('' === $needle) {
            return false;
        }

        return false !== mb_strpos(self::normalize($haystack), $needle);
    }
}
