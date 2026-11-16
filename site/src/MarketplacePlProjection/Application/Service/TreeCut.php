<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Application\Service;

use App\Finance\Application\DTO\PlPreviewRow;
use App\Finance\Enum\PLCategoryType;

/**
 * Экран «ОПиУ» повторяет дерево статей компании до границы — строки с кодом `CM3` и следующей за
 * ней маржинальности `CM3K`. Статьи ниже границы с суммой — отдельным списком, чтобы ничего не
 * терялось. Границы нет (своё дерево без кода) — дерево целиком.
 */
final class TreeCut
{
    public const BOUNDARY = 'CM3';
    private const BOUNDARY_RATIO = 'CM3K';

    /**
     * @param list<PlPreviewRow> $rows дерево в порядке справочника
     *
     * @return array{rows: list<PlPreviewRow>, below: list<PlPreviewRow>, found: bool}
     */
    public static function cut(array $rows): array
    {
        $last = null;
        foreach ($rows as $i => $row) {
            if (self::BOUNDARY === $row->code) {
                $last = self::BOUNDARY_RATIO === ($rows[$i + 1]->code ?? null) ? $i + 1 : $i;
                break;
            }
        }
        if (null === $last) {
            return ['rows' => $rows, 'below' => [], 'found' => false];
        }

        $below = [];
        foreach (\array_slice($rows, $last + 1) as $row) {
            if (PLCategoryType::LEAF_INPUT === $row->type && [] !== array_filter($row->values, static fn (float $v): bool => abs($v) >= 0.005)) {
                $below[] = $row;
            }
        }

        return ['rows' => \array_slice($rows, 0, $last + 1), 'below' => $below, 'found' => true];
    }
}
