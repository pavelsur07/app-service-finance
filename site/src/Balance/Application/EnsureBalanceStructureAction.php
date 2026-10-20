<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Infrastructure\Query\CompaniesWithoutBalanceStructureQuery;
use Doctrine\DBAL\Connection;

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
        private EnsureBalanceAccountsAction $accounts,
        private Connection $db,
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
        // Как у новой компании: вместе со структурой — базовые учётные счета шаблона, в одной
        // транзакции, иначе сбой счетов оставил бы структуру, которую ленивый путь уже не повторит.
        return $this->db->transactional(function () use ($companyId): bool {
            if (!($this->seed)($companyId)) {
                return false;
            }
            ($this->accounts)($companyId);

            return true;
        });
    }
}
