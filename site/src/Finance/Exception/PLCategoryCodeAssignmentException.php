<?php

declare(strict_types=1);

namespace App\Finance\Exception;

/**
 * Код статье ОПиУ не присвоен: статьи нет у компании, она не строка ввода, у неё уже другой код
 * или код занят другой статьёй компании.
 */
final class PLCategoryCodeAssignmentException extends \RuntimeException
{
    public static function notFound(string $categoryId): self
    {
        return new self(sprintf('Статья ОПиУ %s не найдена у компании.', $categoryId));
    }

    public static function notLeafInput(string $categoryId): self
    {
        return new self(sprintf('Статья ОПиУ %s не LEAF_INPUT — код строки ввода ей не присваивается.', $categoryId));
    }

    public static function hasOtherCode(string $categoryId, string $currentCode): self
    {
        return new self(sprintf('У статьи ОПиУ %s уже код «%s».', $categoryId, $currentCode));
    }

    public static function codeTaken(string $code, string $otherCategoryId): self
    {
        return new self(sprintf('Код «%s» уже у статьи ОПиУ %s.', $code, $otherCategoryId));
    }
}
