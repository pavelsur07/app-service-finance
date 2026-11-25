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

/** Склад → счёт актива Баланса, «не учитывать» или снять назначение (нет счёта и не исключён). */
final readonly class SaveStoreBalanceLinkAction
{
    public function __construct(private Connection $db, private MoySkladStoreRepository $stores, private BalanceFacade $balance, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId, string $connectionId, string $storeExternalId, ?string $balanceAccountId, bool $excluded, string $actorUserId): void
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
        $key = [$companyId, $connectionId, $store->getExternalId()];
        if (null === $balanceAccountId && !$excluded) {
            $this->db->executeStatement('DELETE FROM moysklad_store_balance_links WHERE company_id = ? AND connection_id = ? AND store_external_id = ?', $key);

            return;
        }
        $this->db->executeStatement('INSERT INTO moysklad_store_balance_links (company_id, connection_id, store_external_id, balance_account_id, excluded, actor_user_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (company_id, connection_id, store_external_id)
            DO UPDATE SET balance_account_id = EXCLUDED.balance_account_id, excluded = EXCLUDED.excluded, actor_user_id = EXCLUDED.actor_user_id, updated_at = EXCLUDED.updated_at',
            [...$key, $balanceAccountId, $excluded ? 'true' : 'false', $actorUserId, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v')]);
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
