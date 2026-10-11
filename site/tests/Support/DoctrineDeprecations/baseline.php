<?php

declare(strict_types=1);

/*
 * Известные депрекейшены Doctrine, которые гейт пропускает.
 *
 * Ключ — ссылка deprecation (как её печатает гейт), значение — где и почему.
 * Гранулярность — ссылка: пока она здесь, новые вызовы того же вида гейт
 * пропускает. Исправили все вызовы — удалите строку; добавлять — только с
 * пояснением и задачей на исправление.
 */
return [
    // ORM 4: направление сортировки — SortDirection, а не строка. ~280 вызовов
    // orderBy()/addOrderBy() с 'ASC'/'DESC' в src/.
    'https://github.com/doctrine/orm/issues/11313' => 'QueryBuilder::orderBy(…, string) → SortDirection',
];
