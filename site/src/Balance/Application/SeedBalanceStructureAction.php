<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\DTO\BalanceStructureTemplateNode;
use App\Balance\Entity\BalanceCategory;
use App\Balance\Security\BalanceAccess;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final readonly class SeedBalanceStructureAction
{
    public function __construct(private EntityManagerInterface $entityManager, private Connection $db, private BalanceAccess $access, private BalanceStructureTemplateReader $template)
    {
    }

    public function __invoke(string $companyId, ?string $actorId = null): bool
    {
        Assert::uuid($companyId);
        // Шаблон читаем и проверяем до транзакции: битый файл не должен оставить компанию с половиной структуры.
        $template = $this->template->read();
        if (null !== $actorId) {
            $this->access->require($companyId, $actorId, 'manage');
        }

        return $this->db->transactional(function () use ($companyId, $actorId, $template): bool {
            if (null !== $actorId) {
                $this->access->lockForMutation($companyId, $actorId, 'manage');
            }
            $this->db->executeStatement('INSERT INTO balance_books (id,company_id,initialized,version,next_document_number,next_posting_sequence,created_at,updated_at) VALUES (?,?,false,0,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT(company_id) DO NOTHING', [Uuid::uuid7()->toString(), $companyId]);
            $bookId = $this->db->fetchOne('SELECT id FROM balance_books WHERE company_id=? FOR UPDATE', [$companyId]);
            if ($this->db->fetchOne('SELECT 1 FROM balance_articles WHERE company_id=? LIMIT 1', [$companyId])) {
                return false;
            }
            $this->createNodes($companyId, $template, null);
            $this->entityManager->flush();
            $this->db->insert('balance_audit_events', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'object_type' => 'book', 'object_id' => $bookId, 'action' => null === $actorId ? 'system_seed' : 'seed', 'author_id' => $actorId, 'changes' => '{}', 'created_at' => date('Y-m-d H:i:s')]);

            return true;
        });
    }

    /**
     * @param list<BalanceStructureTemplateNode> $nodes
     */
    private function createNodes(string $companyId, array $nodes, ?BalanceCategory $parent): void
    {
        foreach ($nodes as $node) {
            $category = new BalanceCategory(Uuid::uuid7()->toString(), $companyId);
            $category->setName($node->name)->setCode($node->code)->setType($node->type)->setKind($node->kind)->setParent($parent)->setSortOrder($node->sortOrder);
            $this->entityManager->persist($category);
            $this->createNodes($companyId, $node->children, $category);
        }
    }
}
