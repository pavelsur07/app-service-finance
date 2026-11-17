<?php

declare(strict_types=1);

namespace App\Company\Facade;

/**
 * Группа консолидации: головная компания (эталон дерева статей) и её участники.
 * Задаётся в настройках головной компании.
 */
interface ConsolidationGroupFacadeInterface
{
    /** Головная компания группы, в которую входит компания (для головной — она сама); null — вне группы. */
    public function headOf(string $companyId): ?string;

    /**
     * Все компании группы, головная первой; пусто, если компания не головная.
     *
     * @return list<string>
     */
    public function members(string $headCompanyId): array;
}
