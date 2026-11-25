<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\Balance\Facade\BalanceFacade;
use App\MoySklad\Exception\StoreBalanceException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Запись остатков складов в Баланс: корреспондирующий счёт и включение. Автор фоновых проводок — тот, кто сохранил;
 * включить может только пользователь с правами «подготовить» и «провести» в Балансе.
 */
final readonly class SaveStoreBalanceSettingsAction
{
    public function __construct(private Connection $db, private BalanceFacade $balance, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId, string $connectionId, ?string $counterAccountId, bool $enabled, string $actorUserId): void
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::uuid($actorUserId);
        if (null !== $counterAccountId) {
            $counterAccountId = strtolower($counterAccountId);
            $ids = array_map(static fn ($account): string => $account->id, $this->balance->getPostingAccounts($companyId));
            if (!in_array($counterAccountId, $ids, true)) {
                throw new StoreBalanceException('account_unavailable', 'Выберите действующий счёт Баланса.');
            }
        }
        if ($enabled && null === $counterAccountId) {
            throw new StoreBalanceException('counter_required', 'Выберите корреспондирующий счёт для изменения остатков.');
        }
        if ($enabled && !$this->balance->canPostFromSource($companyId, $actorUserId)) {
            throw new StoreBalanceException('actor_forbidden', 'Включить запись может пользователь с правом проводить документы в Балансе.');
        }
        $this->db->executeStatement('INSERT INTO moysklad_store_balance_settings (company_id, connection_id, counter_account_id, enabled, actor_user_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (company_id, connection_id)
            DO UPDATE SET counter_account_id = EXCLUDED.counter_account_id, enabled = EXCLUDED.enabled, actor_user_id = EXCLUDED.actor_user_id, updated_at = EXCLUDED.updated_at',
            [$companyId, $connectionId, $counterAccountId, $enabled ? 'true' : 'false', $actorUserId, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v')]);
    }
}
