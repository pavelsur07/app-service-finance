<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Security\BalanceAccess;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final readonly class SeedBalanceStructureAction
{
    public function __construct(private EntityManagerInterface $entityManager, private Connection $db, private BalanceAccess $access)
    {
    }

    public function __invoke(string $companyId, ?string $actorId = null): bool
    {
        Assert::uuid($companyId);
        if (null !== $actorId) {
            $this->access->require($companyId, $actorId, 'manage');
        }

        return $this->db->transactional(function () use ($companyId, $actorId): bool {
            if (null !== $actorId) {
                $this->access->lockForMutation($companyId, $actorId, 'manage');
            }
            $this->db->executeStatement('INSERT INTO balance_books (id,company_id,initialized,version,next_document_number,next_posting_sequence,created_at,updated_at) VALUES (?,?,false,0,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT(company_id) DO NOTHING', [Uuid::uuid7()->toString(), $companyId]);
            $bookId = $this->db->fetchOne('SELECT id FROM balance_books WHERE company_id=? FOR UPDATE', [$companyId]);
            if ($this->db->fetchOne('SELECT 1 FROM balance_articles WHERE company_id=? LIMIT 1', [$companyId])) {
                return false;
            }
            $current = $this->article($companyId, 'Оборотные активы', 'CURRENT_ASSETS', BalanceCategoryType::ASSET, null, 'group', 10);
            $this->article($companyId, 'Денежные средства', 'CASH', BalanceCategoryType::ASSET, $current, 'article', 10);
            $this->article($companyId, 'Внеоборотные активы', 'NON_CURRENT_ASSETS', BalanceCategoryType::ASSET, null, 'article', 20);
            $equity = $this->article($companyId, 'Капитал', 'EQUITY', BalanceCategoryType::PASSIVE, null, 'group', 30);
            $this->article($companyId, 'Внесенный капитал', 'CONTRIBUTED_CAPITAL', BalanceCategoryType::PASSIVE, $equity, 'article', 10);
            $this->article($companyId, 'Накопленный финансовый результат', 'RETAINED_RESULT', BalanceCategoryType::PASSIVE, $equity, 'article', 20);
            $liabilities = $this->article($companyId, 'Обязательства', 'LIABILITIES', BalanceCategoryType::PASSIVE, null, 'group', 40);
            $this->article($companyId, 'Краткосрочные обязательства', 'CURRENT_LIABILITIES', BalanceCategoryType::PASSIVE, $liabilities, 'article', 10);
            $this->article($companyId, 'Долгосрочные обязательства', 'LONG_LIABILITIES', BalanceCategoryType::PASSIVE, $liabilities, 'article', 20);
            $this->entityManager->flush();
            $this->db->insert('balance_audit_events', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'object_type' => 'book', 'object_id' => $bookId, 'action' => null === $actorId ? 'system_seed' : 'seed', 'author_id' => $actorId, 'changes' => '{}', 'created_at' => date('Y-m-d H:i:s')]);

            return true;
        });
    }

    private function article(string $companyId, string $name, string $code, BalanceCategoryType $type, ?BalanceCategory $parent, string $kind, int $order): BalanceCategory
    {
        $category = new BalanceCategory(Uuid::uuid7()->toString(), $companyId);
        $category->setName($name)->setCode($code)->setType($type)->setKind($kind)->setParent($parent)->setSortOrder($order);
        $this->entityManager->persist($category);

        return $category;
    }
}
