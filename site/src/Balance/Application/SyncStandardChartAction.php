<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\DTO\BalanceStructureTemplateAccount;
use App\Balance\DTO\BalanceStructureTemplateNode;
use App\Company\Enum\BusinessProfile;
use App\Company\Facade\CompanyFacade;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Типовой справочник счетов: добирает недостающие по коду статьи и счета шаблона структуры.
 *
 * - Статья: нет кода у компании → создаётся под родителем по коду, после предыдущей статьи шаблона;
 *   родитель отсутствует или в архиве — поддерево пропускается.
 * - Счёт: нет кода у компании → создаётся на статье по коду; статья в архиве (по цепочке) или не конечная — пропуск.
 * - Существующие статьи и счета (включая счёт пользователя с типовым кодом) не меняются.
 * - Однократно на версию шаблона: отметка `balance_audit_events` (book, `chart_sync`) — удалённое пользователем
 *   не возвращается. Запись — как у ручного создания (счёт, нулевое состояние, аудит `system_seed`) под блокировкой
 *   книги; книга не инициализируется. Транзакцией владеет вызывающий или собственная (`transactional` вложенный).
 */
final readonly class SyncStandardChartAction
{
    public function __construct(
        private Connection $db,
        private BalanceStructureTemplateReader $template,
        private LoggerInterface $logger,
        private CompanyFacade $companies,
    ) {
    }

    /**
     * @param bool $dryRun только посчитать: без записи, блокировки и отметки
     * @param BusinessProfile|null $profile профиль, если вызывающий его уже знает; иначе — профиль компании
     *
     * @return int число созданных (в dry-run — недостающих) статей и счетов
     */
    public function __invoke(string $companyId, bool $dryRun = false, ?BusinessProfile $profile = null): int
    {
        Assert::uuid($companyId);
        if ($this->synced($companyId)) {
            return 0;
        }
        // Дерево добирается по шаблону, которым засеяно (смена профиля существующее дерево не меняет).
        $seeded = $this->db->fetchOne("SELECT changes->>'template' FROM balance_audit_events WHERE company_id=? AND object_type='book' AND action='system_seed' ORDER BY created_at LIMIT 1", [$companyId]);
        $profile = (\is_string($seeded) ? BusinessProfile::tryFrom($seeded) : null) ?? $profile ?? $this->companies->getBusinessProfile($companyId);
        // Шаблон читаем до транзакции: битый файл не должен оставить половину справочника.
        $nodes = $this->template->read($profile);

        [$created, $skipped] = $this->db->transactional(function () use ($companyId, $nodes, $dryRun, $profile): array {
            // Блокировка книги, как у сида и ручной правки структуры; нет книги — нет и структуры.
            $bookId = $this->db->fetchOne('SELECT id FROM balance_books WHERE company_id=?'.($dryRun ? '' : ' FOR UPDATE'), [$companyId]);
            if (false === $bookId || (!$dryRun && $this->synced($companyId))) {
                return [0, []];
            }
            /** @var array<string, array{id: ?string, parent_id: ?string, kind: string, level: int|string, is_archived: bool|int|string, type?: string}> $articles */
            $articles = $this->db->fetchAllAssociativeIndexed('SELECT code, id, parent_id, kind, level, is_archived, type FROM balance_articles WHERE company_id=? AND code IS NOT NULL', [$companyId]);
            $state = new StandardChartState(
                $articles,
                array_fill_keys($this->db->fetchFirstColumn('SELECT code FROM balance_accounts WHERE company_id=?', [$companyId]), true),
            );
            if ([] === $state->articles) {
                return [0, []]; // структуры нет — её создаёт SeedBalanceStructureAction, не справочник
            }
            $this->syncNodes($companyId, $nodes, null, false, $dryRun, $state);
            if (!$dryRun) {
                $this->audit($companyId, 'book', (string) $bookId, 'chart_sync', ['template' => $profile->value, 'version' => BalanceStructureTemplateReader::VERSION, 'created' => $state->created]);
            }

            return [$state->created, $state->skipped];
        });

        if ([] !== $skipped && !$dryRun) {
            $this->logger->warning('Standard balance chart: items skipped, article missing or archived', ['companyId' => $companyId, 'codes' => $skipped]);
        }

        return $created;
    }

    /**
     * Сколько счетов шаблон заводит компании без счетов — для отчёта dry-run, пока структуры ещё нет.
     */
    public function templateAccountCount(BusinessProfile $profile): int
    {
        $count = static function (array $nodes) use (&$count): int {
            $sum = 0;
            foreach ($nodes as $node) {
                $sum += \count($node->accounts) + $count($node->children);
            }

            return $sum;
        };

        return $count($this->template->read($profile));
    }

    private function synced(string $companyId): bool
    {
        return false !== $this->db->fetchOne("SELECT 1 FROM balance_audit_events WHERE company_id=? AND object_type='book' AND action='chart_sync' AND (changes->>'version')::int = ? LIMIT 1",
            [$companyId, BalanceStructureTemplateReader::VERSION]);
    }

    /**
     * @param list<BalanceStructureTemplateNode> $nodes
     * @param array{id: ?string, parent_id: ?string, kind: string, level: int|string, is_archived: bool|int|string, type?: string}|null $parent
     */
    private function syncNodes(string $companyId, array $nodes, ?array $parent, bool $parentArchived, bool $dryRun, StandardChartState $state): void
    {
        $previous = null;
        foreach ($nodes as $node) {
            $row = $state->articles[$node->code] ?? null;
            if (null === $row) {
                // Создаётся только конечная статья под существующей совместимой группой; нет группы — поддерево пропускается.
                if ($parentArchived || 'article' !== $node->kind || !$this->fits($node, $parent)) {
                    $state->skipped[] = $node->code;
                    $previous = $node->code;

                    continue;
                }
                $row = $this->createArticle($companyId, $node, $parent, $previous, $state, $dryRun);
                ++$state->created;
            }
            $archived = $parentArchived || \in_array($row['is_archived'], [true, 1, '1', 't'], true);
            foreach ($node->accounts as $account) {
                if (isset($state->accounts[$account->code])) {
                    continue;
                }
                if ($archived || 'article' !== $row['kind']) {
                    $state->skipped[] = $account->code;

                    continue;
                }
                if (!$dryRun && null !== $row['id']) {
                    $this->insertAccount($companyId, $row['id'], $account);
                }
                $state->accounts[$account->code] = true;
                ++$state->created;
            }
            $this->syncNodes($companyId, $node->children, $row, $archived, $dryRun, $state);
            $previous = $node->code;
        }
    }

    /**
     * Правила `BalanceCategory::setParent()`: родитель — существующая группа той же стороны, глубина ≤ 4.
     *
     * @param array{id: ?string, parent_id: ?string, kind: string, level: int|string, is_archived: bool|int|string, type?: string}|null $parent
     */
    private function fits(BalanceStructureTemplateNode $node, ?array $parent): bool
    {
        return null !== $parent && null !== $parent['id'] && 'group' === $parent['kind']
            && ($parent['type'] ?? null) === $node->type->value && (int) $parent['level'] < 4;
    }

    /**
     * Новая статья — сразу после предыдущей статьи шаблона того же родителя (следующие сдвигаются), иначе в конец.
     *
     * @param array{id: ?string, parent_id: ?string, kind: string, level: int|string, is_archived: bool|int|string, type?: string}|null $parent
     *
     * @return array{id: ?string, parent_id: ?string, kind: string, level: int|string, is_archived: bool, type: string}
     */
    private function createArticle(string $companyId, BalanceStructureTemplateNode $node, ?array $parent, ?string $previous, StandardChartState $state, bool $dryRun): array
    {
        $parentId = $parent['id'] ?? null;
        $row = ['id' => null, 'parent_id' => $parentId, 'kind' => $node->kind, 'level' => null === $parent ? 1 : (int) $parent['level'] + 1, 'is_archived' => false, 'type' => $node->type->value];
        if ($dryRun) {
            return $state->articles[$node->code] = $row;
        }
        $siblings = null === $parentId ? 'company_id = ? AND parent_id IS NULL' : 'company_id = ? AND parent_id = ?';
        $params = null === $parentId ? [$companyId] : [$companyId, $parentId];
        $after = null !== $previous && isset($state->articles[$previous]['id']) && $state->articles[$previous]['parent_id'] === $parentId
            ? $this->db->fetchOne('SELECT sort_order FROM balance_articles WHERE id = ?', [$state->articles[$previous]['id']]) : false;
        if (false !== $after) {
            $sort = (int) $after + 1;
            $this->db->executeStatement('UPDATE balance_articles SET sort_order = sort_order + 1 WHERE '.$siblings.' AND sort_order >= ?', [...$params, $sort]);
        } else {
            $sort = (int) $this->db->fetchOne('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM balance_articles WHERE '.$siblings, $params);
        }
        $now = date('Y-m-d H:i:s');
        $row['id'] = Uuid::uuid7()->toString();
        $data = ['parent_id' => $parentId, 'name' => $node->name, 'code' => $node->code, 'type' => $node->type->value, 'kind' => $node->kind, 'level' => $row['level'], 'sort_order' => $sort];
        $this->db->insert('balance_articles', $data + ['id' => $row['id'], 'company_id' => $companyId, 'is_archived' => 0, 'is_visible' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->audit($companyId, 'article', $row['id'], 'system_seed', ['before' => null, 'after' => $data]);

        return $state->articles[$node->code] = $row;
    }

    private function insertAccount(string $companyId, string $articleId, BalanceStructureTemplateAccount $account): void
    {
        $now = date('Y-m-d H:i:s');
        $accountId = Uuid::uuid7()->toString();
        $data = ['article_id' => $articleId, 'name' => $account->name, 'code' => $account->code, 'allow_negative' => $account->allowNegative ? 1 : 0, 'updated_at' => $now];

        $this->db->insert('balance_accounts', $data + ['id' => $accountId, 'company_id' => $companyId, 'is_archived' => 0, 'created_at' => $now]);
        $this->db->insert('balance_account_states', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'account_id' => $accountId, 'balance' => '0', 'journal_version' => 0, 'updated_at' => $now]);
        $this->audit($companyId, 'account', $accountId, 'system_seed', ['before' => null, 'after' => $data]);
    }

    /** @param array<string, mixed> $changes */
    private function audit(string $companyId, string $objectType, string $objectId, string $action, array $changes): void
    {
        $this->db->insert('balance_audit_events', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'object_type' => $objectType, 'object_id' => $objectId,
            'action' => $action, 'author_id' => null, 'changes' => json_encode($changes, \JSON_THROW_ON_ERROR), 'created_at' => date('Y-m-d H:i:s')]);
    }
}
