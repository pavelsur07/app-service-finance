<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Domain\Policy\BalanceStructurePolicy;
use App\Balance\Entity\BalanceCategory;
use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Repository\BalanceCategoryRepositoryInterface;
use App\Balance\Security\BalanceAccess;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class BalanceStructureService
{
    public function __construct(
        private Connection $db,
        private EntityManagerInterface $em,
        private BalanceCategoryRepositoryInterface $categories,
        private BalanceStructurePolicy $policy,
        private BalanceAccess $access,
    ) {
    }

    public function saveCategory(string $companyId, string $actorId, string $name, BalanceCategoryType $type, ?string $parentId, ?string $code, string $kind = 'group', ?string $id = null, bool $visible = true): string
    {
        $this->access->require($companyId, $actorId, 'manage');

        return $this->transaction(function () use ($companyId, $actorId, $name, $type, $parentId, $code, $kind, $id, $visible): string {
            $this->lock($companyId, $actorId);
            $this->validateNameCode($name, $code);
            if (!in_array($kind, ['group', 'article'], true)) {
                throw new BalanceLedgerException('Неизвестное назначение статьи.');
            }
            $category = null === $id ? new BalanceCategory(Uuid::uuid7()->toString(), $companyId) : $this->category($companyId, $id);
            $before = null === $id ? null : $this->categoryData($category);
            $parentId = '' === $parentId ? null : $parentId;
            $code = null === $code || '' === trim($code) ? null : trim($code);
            $changedClassification = $category->getParent()?->getId() !== $parentId || $category->getType() !== $type || $category->getKind() !== $kind;
            if (null !== $id && $changedClassification) {
                $this->assertUnusedSubtree($companyId, $id);
                if ($category->getType() !== $type && $this->hasContents($companyId, $id)) {
                    throw new BalanceLedgerException('Тип можно изменить только у пустой статьи.');
                }
                if ($category->getKind() !== $kind && $this->hasContents($companyId, $id)) {
                    throw new BalanceLedgerException('Назначение можно изменить только у пустой статьи.');
                }
            }
            if (null !== $id && $category->isArchived()) {
                throw new BalanceLedgerException('Сначала восстановите статью из архива.');
            }
            $this->policy->assertCodeIsUnique($companyId, $code, $id);
            $category->setType($type);
            $this->policy->assertCanSetParent($category, $parentId, $companyId);
            $category->setName(trim($name))->setCode($code)->setKind($kind)->setIsVisible($visible);
            if (null === $before || $before['parentId'] !== $parentId) {
                $category->setSortOrder($this->categories->getNextSortOrder($companyId, $category->getParent()));
            }
            if (null === $id) {
                $this->em->persist($category);
            }
            $this->em->flush();
            $this->audit($companyId, $actorId, 'article', $category->getId(), null === $id ? 'create' : 'update', ['before' => $before, 'after' => $this->categoryData($category)]);

            return $category->getId();
        });
    }

    public function archiveCategory(string $companyId, string $actorId, string $id, bool $archive): void
    {
        $this->access->require($companyId, $actorId, 'manage');
        $this->transaction(function () use ($companyId, $actorId, $id, $archive): void {
            $this->lock($companyId, $actorId);
            $category = $this->category($companyId, $id);
            if ($archive && ($this->db->fetchOne('SELECT 1 FROM balance_articles WHERE company_id=? AND parent_id=? AND NOT is_archived LIMIT 1', [$companyId, $id]) || $this->db->fetchOne('SELECT 1 FROM balance_accounts WHERE company_id=? AND article_id=? AND NOT is_archived LIMIT 1', [$companyId, $id]))) {
                throw new BalanceLedgerException('Сначала архивируйте дочерние статьи и счета.');
            }
            if (!$archive && $category->getParent()?->isArchived()) {
                throw new BalanceLedgerException('Сначала восстановите родительскую статью.');
            }
            $category->setIsArchived($archive);
            $this->em->flush();
            $this->audit($companyId, $actorId, 'article', $id, $archive ? 'archive' : 'restore');
        });
    }

    public function deleteCategory(string $companyId, string $actorId, string $id): void
    {
        $this->access->require($companyId, $actorId, 'manage');
        $this->transaction(function () use ($companyId, $actorId, $id): void {
            $this->lock($companyId, $actorId);
            $category = $this->category($companyId, $id);
            if ($this->hasContents($companyId, $id) || $this->hasDocumentReference($companyId, 'article', $id)) {
                throw new BalanceLedgerException('Статья использовалась в документах или содержит счета или дочерние статьи. Используйте архив.');
            }
            $this->em->remove($category);
            $this->em->flush();
            $this->audit($companyId, $actorId, 'article', $id, 'delete');
        });
    }

    public function moveCategory(string $companyId, string $actorId, string $id, string $direction): void
    {
        $this->access->require($companyId, $actorId, 'manage');
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new BalanceLedgerException('Неизвестное направление сортировки.');
        }
        $this->transaction(function () use ($companyId, $actorId, $id, $direction): void {
            $this->lock($companyId, $actorId);
            $category = $this->category($companyId, $id);
            $siblings = $this->categories->findSiblings($companyId, $category->getParent());
            usort($siblings, static fn (BalanceCategory $a, BalanceCategory $b): int => [$a->getSortOrder(), $a->getId()] <=> [$b->getSortOrder(), $b->getId()]);
            foreach ($siblings as $i => $sibling) {
                if ($sibling->getId() === $id) {
                    $other = $siblings[$i + ('up' === $direction ? -1 : 1)] ?? null;
                    if (null !== $other) {
                        $beforeOrder = [];
                        foreach ($siblings as $item) {
                            $beforeOrder[$item->getId()] = $item->getSortOrder();
                        }
                        if ($category->getSortOrder() === $other->getSortOrder()) {
                            foreach ($siblings as $position => $item) {
                                $item->setSortOrder(($position + 1) * 10);
                            }
                        }
                        $this->categories->swapSortOrder($category, $other);
                        $this->em->flush();
                        $afterOrder = [];
                        foreach ($siblings as $item) {
                            $afterOrder[$item->getId()] = $item->getSortOrder();
                        }
                        $this->audit($companyId, $actorId, 'article', $id, 'sort', ['direction' => $direction, 'before' => $beforeOrder, 'after' => $afterOrder]);
                    }
                    break;
                }
            }
        });
    }

    public function saveAccount(string $companyId, string $actorId, string $articleId, string $name, string $code, bool $allowNegative, ?string $id = null): string
    {
        $this->access->require($companyId, $actorId, 'manage');

        return $this->transaction(function () use ($companyId, $actorId, $articleId, $name, $code, $allowNegative, $id): string {
            $this->lock($companyId, $actorId);
            $this->validateNameCode($name, $code);
            if ('' === trim($code)) {
                throw new BalanceLedgerException('Укажите код счета.');
            }
            $article = $this->category($companyId, $articleId);
            if ('article' !== $article->getKind() || $article->isArchived()) {
                throw new BalanceLedgerException('Счет должен принадлежать активной конечной статье.');
            }
            $before = null === $id ? null : $this->account($companyId, $id);
            if (null !== $before && (bool) $before['is_archived']) {
                throw new BalanceLedgerException('Сначала восстановите счет из архива.');
            }
            if (null !== $before && ((string) $before['article_id'] !== $articleId || (bool) $before['allow_negative'] !== $allowNegative) && $this->accountUsed($companyId, (string) $id)) {
                throw new BalanceLedgerException('Нельзя менять статью или правило отрицательного остатка использованного счета.');
            }
            if ($this->db->fetchOne('SELECT id FROM balance_accounts WHERE company_id=? AND code=? AND id<>? LIMIT 1', [$companyId, trim($code), $id ?? Uuid::NIL])) {
                throw new BalanceLedgerException('Код счета должен быть уникален в компании.');
            }
            $accountId = $id ?? Uuid::uuid7()->toString();
            $data = ['article_id' => $articleId, 'name' => trim($name), 'code' => trim($code), 'allow_negative' => $allowNegative ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
            if (null === $id) {
                $this->db->insert('balance_accounts', $data + ['id' => $accountId, 'company_id' => $companyId, 'is_archived' => 0, 'created_at' => date('Y-m-d H:i:s')]);
                $this->db->insert('balance_account_states', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'account_id' => $accountId, 'balance' => '0', 'journal_version' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
            } else {
                $this->db->update('balance_accounts', $data, ['company_id' => $companyId, 'id' => $id]);
            }
            $this->audit($companyId, $actorId, 'account', $accountId, null === $id ? 'create' : 'update', ['before' => $before, 'after' => $data]);

            return $accountId;
        });
    }

    public function archiveAccount(string $companyId, string $actorId, string $id, bool $archive): void
    {
        $this->access->require($companyId, $actorId, 'manage');
        $this->transaction(function () use ($companyId, $actorId, $id, $archive): void {
            $this->lock($companyId, $actorId);
            $account = $this->account($companyId, $id);
            if (!$archive && $this->category($companyId, (string) $account['article_id'])->isArchived()) {
                throw new BalanceLedgerException('Сначала восстановите статью счета.');
            }
            $this->db->update('balance_accounts', ['is_archived' => $archive ? 1 : 0], ['company_id' => $companyId, 'id' => $id]);
            $this->audit($companyId, $actorId, 'account', $id, $archive ? 'archive' : 'restore');
        });
    }

    public function deleteAccount(string $companyId, string $actorId, string $id): void
    {
        $this->access->require($companyId, $actorId, 'manage');
        $this->transaction(function () use ($companyId, $actorId, $id): void {
            $this->lock($companyId, $actorId);
            $this->account($companyId, $id);
            if ($this->hasDocumentReference($companyId, 'account', $id) || $this->db->fetchOne('SELECT 1 FROM balance_operation_lines WHERE company_id=? AND account_id=? LIMIT 1', [$companyId, $id])) {
                throw new BalanceLedgerException('Счет используется в документах. Используйте архив.');
            }
            $this->db->delete('balance_account_states', ['company_id' => $companyId, 'account_id' => $id]);
            $this->db->delete('balance_accounts', ['company_id' => $companyId, 'id' => $id]);
            $this->audit($companyId, $actorId, 'account', $id, 'delete');
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        try {
            return $this->db->transactional(static fn () => $work());
        } catch (\Throwable $exception) {
            // DBAL rollback does not reset Doctrine's managed entity changes.
            $this->em->clear();
            throw $exception;
        }
    }

    private function lock(string $companyId, string $actorId): void
    {
        $this->access->lockForMutation($companyId, $actorId, 'manage');
        $this->db->executeStatement('INSERT INTO balance_books (id,company_id,initialized,version,next_document_number,next_posting_sequence,created_at,updated_at) VALUES (?,?,false,0,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT(company_id) DO NOTHING', [Uuid::uuid7()->toString(), $companyId]);
        $this->db->fetchOne('SELECT id FROM balance_books WHERE company_id=? FOR UPDATE', [$companyId]);
    }

    private function category(string $companyId, string $id): BalanceCategory
    {
        if (!Uuid::isValid($id)) {
            throw new BalanceLedgerException('Статья не найдена.', 404);
        }

        return $this->categories->findByIdAndCompany($id, $companyId) ?? throw new BalanceLedgerException('Статья не найдена.', 404);
    }

    /** @return array<string, mixed> */
    private function account(string $companyId, string $id): array
    {
        if (!Uuid::isValid($id)) {
            throw new BalanceLedgerException('Счет не найден.', 404);
        }

        return $this->db->fetchAssociative('SELECT id,article_id,name,code,allow_negative,is_archived FROM balance_accounts WHERE company_id=? AND id=?', [$companyId, $id]) ?: throw new BalanceLedgerException('Счет не найден.', 404);
    }

    private function hasDocumentReference(string $companyId, string $type, string $id): bool
    {
        return false !== $this->db->fetchOne("SELECT 1 FROM balance_audit_events WHERE company_id=? AND object_type=? AND object_id=? AND action='document_referenced' LIMIT 1", [$companyId, $type, $id]);
    }

    private function accountUsed(string $companyId, string $id): bool
    {
        return false !== $this->db->fetchOne("SELECT 1 FROM balance_operation_lines l JOIN balance_operations o ON o.company_id=l.company_id AND o.id=l.operation_id WHERE l.company_id=? AND l.account_id=? AND o.status='posted' LIMIT 1", [$companyId, $id]);
    }

    private function hasContents(string $companyId, string $id): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM balance_articles WHERE company_id=? AND parent_id=? UNION ALL SELECT 1 FROM balance_accounts WHERE company_id=? AND article_id=? LIMIT 1', [$companyId, $id, $companyId, $id]);
    }

    private function assertUnusedSubtree(string $companyId, string $id): void
    {
        $used = $this->db->fetchOne(<<<'SQL'
WITH RECURSIVE subtree AS (
 SELECT id FROM balance_articles WHERE company_id=:company AND id=:id
 UNION ALL SELECT c.id FROM balance_articles c JOIN subtree s ON c.parent_id=s.id WHERE c.company_id=:company
)
SELECT 1 FROM subtree s JOIN balance_accounts a ON a.article_id=s.id AND a.company_id=:company
JOIN balance_operation_lines l ON l.account_id=a.id AND l.company_id=:company
JOIN balance_operations o ON o.id=l.operation_id AND o.company_id=:company AND o.status='posted' LIMIT 1
SQL, ['company' => $companyId, 'id' => $id]);
        if (false !== $used) {
            throw new BalanceLedgerException('Нельзя переносить или менять назначение использованной структуры.');
        }
    }

    private function validateNameCode(string $name, ?string $code): void
    {
        if ('' === trim($name) || mb_strlen($name) > 255 || (null !== $code && mb_strlen($code) > 64)) {
            throw new BalanceLedgerException('Укажите название до 255 символов и код до 64 символов.');
        }
    }

    /** @return array<string, mixed> */
    private function categoryData(BalanceCategory $category): array
    {
        return ['name' => $category->getName(), 'sortOrder' => $category->getSortOrder(), 'archived' => $category->isArchived(), 'type' => $category->getType()->value, 'parentId' => $category->getParent()?->getId(), 'kind' => $category->getKind(), 'code' => $category->getCode()];
    }

    /** @param array<string, mixed> $changes */
    private function audit(string $companyId, string $actorId, string $type, string $id, string $action, array $changes = []): void
    {
        $this->db->insert('balance_audit_events', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'object_type' => $type, 'object_id' => $id, 'action' => $action, 'author_id' => $actorId, 'changes' => json_encode($changes, \JSON_THROW_ON_ERROR), 'created_at' => date('Y-m-d H:i:s')]);
    }
}
