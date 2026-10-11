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
    // ORM 4: у произвольного (не по ассоциации) join условие — ON, не WITH.
    // FundBalanceService, PaymentPlanMatcher, UserRepository.
    'https://github.com/doctrine/orm/issues/12192' => 'leftJoin(Entity::class, alias, \'WITH\', …) → \'ON\'',
    // ORM 4: #[ORM\Table(uniqueConstraints: …)] не действует — нужен
    // #[ORM\UniqueConstraint]. Catalog Product, ProductBarcode.
    'https://github.com/doctrine/orm/pull/11357' => 'Table::$uniqueConstraints → #[ORM\UniqueConstraint]',
    // DBAL 5: quoteIdentifier() → quoteSingleIdentifier().
    // InventoryStockReportQuery, UserDeletionService (через платформу).
    'https://github.com/doctrine/dbal/pull/6590' => 'Connection::quoteIdentifier() → quoteSingleIdentifier()',
];
