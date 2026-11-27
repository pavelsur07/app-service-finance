<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\Balance\Facade\BalanceFacade;
use App\MoySklad\Exception\StoreBalanceException;
use App\MoySklad\Infrastructure\Repository\MoySkladStoreRepository;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Склад → счёт актива Баланса, «не учитывать» или снять назначение (нет счёта и не исключён).
 * Назначение решает, какой счёт Баланса будет переписан проводкой, поэтому менять его может только
 * пользователь с правом проводить в Балансе. Счёт принадлежит одному подключению и не может быть
 * корреспондирующим — проверки под company advisory-lock, общим с настройками.
 */
final readonly class SaveStoreBalanceLinkAction
{
    public function __construct(private Connection $db, private MoySkladStoreRepository $stores, private BalanceFacade $balance, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId, string $connectionId, string $storeExternalId, ?string $balanceAccountId, bool $excluded, string $actorUserId, bool $onlyIfUnassigned = false): bool
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::uuid($actorUserId);
        $store = Uuid::isValid($storeExternalId) ? $this->stores->findByExternalId($companyId, $connectionId, strtolower($storeExternalId)) : null;
        if (null === $store || $store->isArchived()) {
            throw new StoreBalanceException('store_not_found', 'Склад не найден.');
        }
        if ($excluded) {
            $balanceAccountId = null;
        } elseif (null !== $balanceAccountId && !$this->isAssetAccount($companyId, $balanceAccountId)) {
            throw new StoreBalanceException('account_unavailable', 'Выберите действующий счёт актива Баланса.');
        }
        $balanceAccountId = null === $balanceAccountId ? null : strtolower($balanceAccountId);
        $key = [$companyId, $connectionId, $store->getExternalId()];

        return $this->db->transactional(function () use ($companyId, $connectionId, $balanceAccountId, $excluded, $actorUserId, $key, $onlyIfUnassigned): bool {
            self::lockCompany($this->db, $companyId);
            if (!$this->balance->canPostFromSource($companyId, $actorUserId)) {
                throw new StoreBalanceException('actor_forbidden', 'Назначать счета Баланса складам может пользователь с правом проводить документы в Балансе.');
            }
            // Принятие предложений не перетирает назначение, сделанное вручную после показа страницы: проверка под company-lock.
            if ($onlyIfUnassigned && false !== $this->db->fetchOne('SELECT 1 FROM moysklad_store_balance_links WHERE company_id = ? AND connection_id = ? AND store_external_id = ?', $key)) {
                return false;
            }
            if (null === $balanceAccountId && !$excluded) {
                $this->db->executeStatement('DELETE FROM moysklad_store_balance_links WHERE company_id = ? AND connection_id = ? AND store_external_id = ?', $key);

                return true;
            }
            if (null !== $balanceAccountId) {
                $this->assertAccountFree($companyId, $connectionId, $balanceAccountId);
            }
            $this->db->executeStatement('INSERT INTO moysklad_store_balance_links (company_id, connection_id, store_external_id, balance_account_id, excluded, actor_user_id, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (company_id, connection_id, store_external_id)
                DO UPDATE SET balance_account_id = EXCLUDED.balance_account_id, excluded = EXCLUDED.excluded, actor_user_id = EXCLUDED.actor_user_id, updated_at = EXCLUDED.updated_at',
                [...$key, $balanceAccountId, $excluded ? 'true' : 'false', $actorUserId, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v')]);

            return true;
        });
    }

    /** Общая блокировка назначений и настроек компании (уникальность счёта между подключениями). */
    public static function lockCompany(Connection $db, string $companyId): void
    {
        $db->fetchOne('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', ['moysklad-store-balance-accounts', $companyId]);
    }

    private function assertAccountFree(string $companyId, string $connectionId, string $accountId): void
    {
        if (false !== $this->db->fetchOne('SELECT 1 FROM moysklad_store_balance_links WHERE company_id = ? AND connection_id <> ? AND balance_account_id = ? LIMIT 1', [$companyId, $connectionId, $accountId])) {
            throw new StoreBalanceException('account_taken', 'Этот счёт уже назначен складам другого подключения МойСклад.');
        }
        if (false !== $this->db->fetchOne('SELECT 1 FROM moysklad_store_balance_settings WHERE company_id = ? AND counter_account_id = ? LIMIT 1', [$companyId, $accountId])) {
            throw new StoreBalanceException('account_is_counter', 'Этот счёт выбран корреспондирующим — склад на него назначить нельзя.');
        }
    }

    private function isAssetAccount(string $companyId, string $accountId): bool
    {
        foreach ($this->balance->getPostingAccounts($companyId) as $account) {
            if ($account->id === strtolower($accountId)) {
                return $account->isAsset;
            }
        }

        return false;
    }
}
