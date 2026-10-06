<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Infrastructure\Query\CompaniesWithoutBalanceStructureQuery;

/**
 * Ленивый сид: компании, созданные до появления сида при создании компании, получают
 * стартовую структуру при первом открытии раздела «Баланс». Дешёвая проверка идёт
 * до сида, потому что сам сид берёт блокировку книги, а вызывается на каждый просмотр.
 */
final readonly class EnsureBalanceStructureAction
{
    public function __construct(
        private CompaniesWithoutBalanceStructureQuery $missing,
        private SeedBalanceStructureAction $seed,
    ) {
    }

    /**
     * @return bool true, если структура была создана сейчас
     */
    public function __invoke(string $companyId): bool
    {
        if ([] === ($this->missing)([$companyId])) {
            return false;
        }

        // Системный сид (actor = null): права чтения уже проверены контроллером, аудит — system_seed.
        return ($this->seed)($companyId);
    }
}
