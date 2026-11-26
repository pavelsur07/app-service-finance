<?php

declare(strict_types=1);

namespace App\OneC\Application;

/**
 * Считает открывающие скобки и запятые вне строк до json_decode(): тело в 10 МиБ
 * из мелких значений иначе развернётся в миллионы PHP-массивов.
 */
final class JsonComplexity
{
    public static function exceeds(string $json, int $limit): bool
    {
        $length = \strlen($json);
        if ($length <= $limit) {
            return false;
        }
        $inString = false;
        for ($offset = 0; $offset < $length; ++$offset) {
            $offset += strcspn($json, $inString ? '"\\' : '"{[,', $offset);
            if ($offset >= $length) {
                break;
            }
            if ('"' === $json[$offset]) {
                $inString = !$inString;
            } elseif ($inString) {
                ++$offset;
            } elseif (--$limit < 0) {
                return true;
            }
        }

        return false;
    }
}
