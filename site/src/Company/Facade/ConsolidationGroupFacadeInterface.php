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
     * То же, что headOf(), но из БД и под блокировкой кабинета владельца до конца транзакции
     * вызывающего: состав группы не изменится, пока вызывающий пишет по нему. Брать первой,
     * до блокировок своего модуля.
     */
    public function lockedHeadOf(string $companyId): ?string;

    /**
     * Все компании группы, головная первой; пусто, если компания не головная.
     *
     * @return list<string>
     */
    public function members(string $headCompanyId): array;

    /**
     * Головные компании одним запросом (ночная проверка): companyId => headCompanyId в порядке
     * входа; компании вне группы ключа не получают.
     *
     * @param list<string> $companyIds
     *
     * @return array<string, string>
     */
    public function headsOf(array $companyIds): array;
}
