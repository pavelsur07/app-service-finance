<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\DTO\BalanceStructureTemplateAccount;
use App\Balance\DTO\BalanceStructureTemplateNode;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Базовые учётные счета из шаблона структуры (`accounts` у конечных статей). Создаются,
 * только если у компании нет ни одного счёта: счета, заведённые пользователем, шаблон не
 * дополняет. Запись — как у ручного создания счёта (`BalanceStructureService::saveAccount`):
 * счёт, нулевое состояние, аудит; книга не инициализируется.
 */
final readonly class EnsureBalanceAccountsAction
{
    public function __construct(
        private Connection $db,
        private BalanceStructureTemplateReader $template,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $dryRun только посчитать: без записи и без блокировки книги
     *
     * @return int число созданных (в dry-run — недостающих) счетов
     */
    public function __invoke(string $companyId, bool $dryRun = false): int
    {
        Assert::uuid($companyId);
        // Шаблон читаем до транзакции: битый файл не должен оставить половину счетов.
        $accountsByArticle = $this->templateAccounts($this->template->read());
        if ([] === $accountsByArticle) {
            return 0;
        }

        [$created, $skipped] = $this->db->transactional(function () use ($companyId, $accountsByArticle, $dryRun): array {
            // Блокировка книги, как у сида и ручной правки структуры; нет книги — нет и структуры.
            if (false === $this->db->fetchOne('SELECT id FROM balance_books WHERE company_id=?'.($dryRun ? '' : ' FOR UPDATE'), [$companyId])) {
                return [0, []];
            }
            if (false !== $this->db->fetchOne('SELECT 1 FROM balance_accounts WHERE company_id=? LIMIT 1', [$companyId])) {
                return [0, []];
            }

            /** @var array<string, string> $articleIds code статьи → id */
            $articleIds = $this->db->fetchAllKeyValue(
                "SELECT code, id FROM balance_articles WHERE company_id=? AND kind='article' AND is_archived=false AND code IN (?)",
                [$companyId, array_keys($accountsByArticle)],
                [ParameterType::STRING, ArrayParameterType::STRING],
            );

            $created = 0;
            $skipped = [];
            foreach ($accountsByArticle as $articleCode => $accounts) {
                foreach ($accounts as $account) {
                    if (!isset($articleIds[$articleCode])) {
                        $skipped[] = $account->code;

                        continue;
                    }
                    if (!$dryRun) {
                        $this->insert($companyId, (string) $articleIds[$articleCode], $account);
                    }
                    ++$created;
                }
            }

            return [$created, $skipped];
        });

        if ([] !== $skipped && !$dryRun) {
            $this->logger->warning('Default balance accounts skipped: article missing or archived', [
                'companyId' => $companyId,
                'accounts' => $skipped,
            ]);
        }

        return $created;
    }

    private function insert(string $companyId, string $articleId, BalanceStructureTemplateAccount $account): void
    {
        $now = date('Y-m-d H:i:s');
        $accountId = Uuid::uuid7()->toString();
        $data = ['article_id' => $articleId, 'name' => $account->name, 'code' => $account->code, 'allow_negative' => $account->allowNegative ? 1 : 0, 'updated_at' => $now];

        $this->db->insert('balance_accounts', $data + ['id' => $accountId, 'company_id' => $companyId, 'is_archived' => 0, 'created_at' => $now]);
        $this->db->insert('balance_account_states', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'account_id' => $accountId, 'balance' => '0', 'journal_version' => 0, 'updated_at' => $now]);
        $this->db->insert('balance_audit_events', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'object_type' => 'account', 'object_id' => $accountId, 'action' => 'system_seed', 'author_id' => null, 'changes' => json_encode(['before' => null, 'after' => $data], \JSON_THROW_ON_ERROR), 'created_at' => $now]);
    }

    /**
     * @param list<BalanceStructureTemplateNode> $nodes
     *
     * @return array<string, list<BalanceStructureTemplateAccount>> code статьи → её счета
     */
    private function templateAccounts(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if ([] !== $node->accounts) {
                $result[$node->code] = $node->accounts;
            }
            $result += $this->templateAccounts($node->children);
        }

        return $result;
    }
}
