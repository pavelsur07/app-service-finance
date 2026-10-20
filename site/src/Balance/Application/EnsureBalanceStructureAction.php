<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Infrastructure\Query\CompaniesWithoutBalanceStructureQuery;
use App\Company\Enum\BusinessProfile;
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
    /**
     * @param BusinessProfile|null $profile профиль, если вызывающий его уже знает; иначе — профиль компании
     */
    public function __invoke(string $companyId, ?BusinessProfile $profile = null): bool
    {
        if ([] === ($this->missing)([$companyId])) {
            return false;
        }

        // Системный сид (actor = null): права чтения уже проверены контроллером, аудит — system_seed.
        // Как у новой компании: вместе со структурой — базовые учётные счета шаблона, в одной
        // транзакции, иначе сбой счетов оставил бы структуру, которую ленивый путь уже не повторит.
        return $this->db->transactional(function () use ($companyId, $profile): bool {
            if (!($this->seed)($companyId, profile: $profile)) {
                return false;
            }
            ($this->accounts)($companyId, profile: $profile);

            return true;
        });
    }
}
