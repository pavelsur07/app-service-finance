<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Security\BalanceAccess;
use App\Company\Facade\CompanyFacade;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class GrantBalanceAccessAction
{
    public function __construct(private readonly Connection $db, private readonly BalanceAccess $access, private readonly CompanyFacade $companies)
    {
    }

    public function __invoke(string $companyId, string $actorId, string $userId, bool $prepare, bool $post, bool $managePeriods, bool $reopenPeriods = false): void
    {
        $this->access->require($companyId, $actorId, 'manage');

        $this->db->transactional(function () use ($companyId, $actorId, $userId, $prepare, $post, $managePeriods, $reopenPeriods): void {
            $this->access->lockForMutation($companyId, $actorId, 'manage');
            if (!$this->db->fetchOne('SELECT id FROM balance_books WHERE company_id=? FOR UPDATE', [$companyId])) {
                throw new BalanceLedgerException('Сначала создайте учетную книгу.');
            }
            $this->access->require($companyId, $actorId, 'manage');
            if (!$this->companies->userHasAccess($companyId, $userId)) {
                throw new BalanceLedgerException('Пользователь не имеет доступа к компании.');
            }
            $this->db->executeStatement('INSERT INTO balance_access_grants (id,company_id,user_id,can_prepare,can_post,can_manage_periods,can_reopen_periods) VALUES (?,?,?,?,?,?,?) ON CONFLICT (company_id,user_id) DO UPDATE SET can_prepare=EXCLUDED.can_prepare,can_post=EXCLUDED.can_post,can_manage_periods=EXCLUDED.can_manage_periods,can_reopen_periods=EXCLUDED.can_reopen_periods', [Uuid::uuid7()->toString(), $companyId, $userId, $prepare, $post, $managePeriods, $reopenPeriods], [\PDO::PARAM_STR, \PDO::PARAM_STR, \PDO::PARAM_STR, \PDO::PARAM_BOOL, \PDO::PARAM_BOOL, \PDO::PARAM_BOOL, \PDO::PARAM_BOOL]);
            $this->db->executeStatement('UPDATE balance_books SET version=version+1,updated_at=? WHERE company_id=?', [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $companyId]);
            $this->db->insert('balance_audit_events', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'object_type' => 'access', 'object_id' => $userId, 'action' => 'grant_changed', 'author_id' => $actorId, 'changes' => json_encode(['prepare' => $prepare, 'post' => $post, 'manage_periods' => $managePeriods, 'reopen_periods' => $reopenPeriods], \JSON_THROW_ON_ERROR), 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        });
    }
}
