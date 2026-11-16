<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

/**
 * Что текущий (легаси) расчёт отнёс бы на статьи ОПиУ за период — для сравнения с проекцией из
 * канона (`MarketplacePlProjection`). Только чтение.
 */
interface LegacyPlPreviewFacadeInterface
{
    /**
     * Суммы по статьям в знаке строки документа `marketplace_pl`, как в «Итого» предпросмотра ОПиУ.
     *
     * @param string|null $marketplace значение `MarketplaceType`; null — все маркетплейсы
     *
     * @return array<string, string> plCategoryId → сумма (bcmath-строка)
     */
    public function amountsByCategory(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $marketplace = null): array;
}
