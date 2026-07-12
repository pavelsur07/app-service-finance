<?php

declare(strict_types=1);

namespace App\Cash\Service\Category;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final readonly class CashflowCategoryStructureMigrator
{
    private const DEFINITIONS = [
        CashflowCategory::CODE_OPERATING => ['name' => 'Операционная деятельность', 'flowKind' => CashflowFlowKind::OPERATING, 'sort' => 10, 'parentCode' => null],
        CashflowCategory::CODE_FINANCING => ['name' => 'Финансовая деятельность', 'flowKind' => CashflowFlowKind::FINANCING, 'sort' => 20, 'parentCode' => null],
        CashflowCategory::CODE_INVESTING => ['name' => 'Инвестиционная деятельность', 'flowKind' => CashflowFlowKind::INVESTING, 'sort' => 30, 'parentCode' => null],
        CashflowCategory::CODE_TECHNICAL => ['name' => 'Технические операции', 'flowKind' => CashflowFlowKind::TECHNICAL, 'sort' => 40, 'parentCode' => null],
        CashflowCategory::CODE_TECHNICAL_IN => ['name' => 'Поступления', 'flowKind' => CashflowFlowKind::TECHNICAL, 'sort' => 10, 'parentCode' => CashflowCategory::CODE_TECHNICAL],
        CashflowCategory::CODE_TECHNICAL_OUT => ['name' => 'Выбытия', 'flowKind' => CashflowFlowKind::TECHNICAL, 'sort' => 20, 'parentCode' => CashflowCategory::CODE_TECHNICAL],
        CashflowCategory::CODE_UNALLOCATED => ['name' => 'Не распределено', 'flowKind' => CashflowFlowKind::OPERATING, 'sort' => 50, 'parentCode' => null],
    ];

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public function findCompanyIds(?string $companyId = null): array
    {
        if (null !== $companyId) {
            return $this->connection->fetchOne('SELECT 1 FROM companies WHERE id = :id', ['id' => $companyId])
                ? [$companyId]
                : [];
        }

        return array_map(
            static fn (mixed $id): string => (string) $id,
            $this->connection->fetchFirstColumn('SELECT id FROM companies ORDER BY id'),
        );
    }

    /**
     * @return array{
     *     companyId: string,
     *     conflicts: list<string>,
     *     categories: array<string, array{id: string, create: bool, name: string, flowKind: string, sort: int, parentId: ?string}>,
     *     rootsToMove: list<string>
     * }
     */
    public function plan(string $companyId): array
    {
        /** @var list<array{id: string, name: string, system_code: ?string, is_system: bool, parent_id: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, system_code, is_system, parent_id FROM cashflow_categories WHERE company_id = :companyId ORDER BY id',
            ['companyId' => $companyId],
        );

        $conflicts = [];
        $resolved = [];
        $assignedIds = [];

        foreach (self::DEFINITIONS as $code => $definition) {
            $candidates = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['system_code'] === $code,
            ));

            if (CashflowCategory::CODE_UNALLOCATED === $code) {
                $legacyCandidates = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => CashflowCategory::SYSTEM_UNALLOCATED === $row['system_code'],
                ));

                if ([] !== $candidates && [] !== $legacyCandidates) {
                    $conflicts[] = sprintf(
                        'Одновременно существуют категории %s и legacy %s; требуется ручной выбор.',
                        CashflowCategory::CODE_UNALLOCATED,
                        CashflowCategory::SYSTEM_UNALLOCATED,
                    );
                    continue;
                }

                if ([] === $candidates) {
                    $candidates = $legacyCandidates;
                }
            }

            if ([] === $candidates) {
                $expectedParentId = null === $definition['parentCode'] ? null : ($resolved[$definition['parentCode']]['id'] ?? null);
                $parentExists = null === $definition['parentCode'] || !($resolved[$definition['parentCode']]['create'] ?? true);

                if ($parentExists) {
                    $candidates = array_values(array_filter(
                        $rows,
                        static fn (array $row): bool => $row['name'] === $definition['name']
                            && $row['parent_id'] === $expectedParentId
                            && null === $row['system_code']
                            && !isset($assignedIds[$row['id']]),
                    ));
                }
            }

            if (count($candidates) > 1) {
                $conflicts[] = sprintf('Код %s: найдено несколько подходящих категорий (%s).', $code, implode(', ', array_column($candidates, 'id')));
                continue;
            }

            $candidate = $candidates[0] ?? null;
            if (null !== $candidate && isset($assignedIds[$candidate['id']])) {
                $conflicts[] = sprintf('Код %s: категория %s уже выбрана для другого системного кода.', $code, $candidate['id']);
                continue;
            }

            $id = null === $candidate ? Uuid::uuid4()->toString() : $candidate['id'];
            $resolved[$code] = [
                'id' => $id,
                'create' => null === $candidate,
                'name' => $definition['name'],
                'flowKind' => $definition['flowKind']->value,
                'sort' => $definition['sort'],
                'parentId' => null === $definition['parentCode'] ? null : ($resolved[$definition['parentCode']]['id'] ?? null),
            ];
            $assignedIds[$id] = true;
        }

        if ([] !== $conflicts || !isset($resolved[CashflowCategory::CODE_OPERATING])) {
            return [
                'companyId' => $companyId,
                'conflicts' => $conflicts,
                'categories' => $resolved,
                'rootsToMove' => [],
            ];
        }

        $rootsToMove = [];
        $childrenByParent = [];
        foreach ($rows as $row) {
            if (null !== $row['parent_id']) {
                $childrenByParent[$row['parent_id']][] = $row['id'];
            }
        }

        foreach ($rows as $row) {
            if (null !== $row['parent_id'] || isset($assignedIds[$row['id']])) {
                continue;
            }

            try {
                $depth = $this->subtreeDepth($row['id'], $childrenByParent);
            } catch (\LogicException) {
                $conflicts[] = sprintf('Категория %s содержит цикл в иерархии.', $row['id']);
                continue;
            }

            if ($depth >= 5) {
                $conflicts[] = sprintf('Категория %s имеет глубину %d и после переноса превысит лимит 5.', $row['id'], $depth);
                continue;
            }

            $rootsToMove[] = $row['id'];
        }

        return [
            'companyId' => $companyId,
            'conflicts' => $conflicts,
            'categories' => $resolved,
            'rootsToMove' => $rootsToMove,
        ];
    }

    /** @param array<string, list<string>> $childrenByParent */
    private function subtreeDepth(string $id, array $childrenByParent, array $path = []): int
    {
        if (isset($path[$id])) {
            throw new \LogicException('Cycle detected.');
        }

        $path[$id] = true;
        $maxChildDepth = 0;
        foreach ($childrenByParent[$id] ?? [] as $childId) {
            $maxChildDepth = max($maxChildDepth, $this->subtreeDepth($childId, $childrenByParent, $path));
        }

        return 1 + $maxChildDepth;
    }

    /**
     * @param array{
     *     companyId: string,
     *     conflicts: list<string>,
     *     categories: array<string, array{id: string, create: bool, name: string, flowKind: string, sort: int, parentId: ?string}>,
     *     rootsToMove: list<string>
     * } $plan
     */
    public function execute(array $plan): void
    {
        if ([] !== $plan['conflicts']) {
            throw new \DomainException('Нельзя применить план с конфликтами.');
        }

        $this->connection->transactional(function () use ($plan): void {
            $this->connection->executeQuery(
                'SELECT pg_advisory_xact_lock(hashtext(:companyId))',
                ['companyId' => $plan['companyId']],
            );
            $this->connection->executeQuery(
                'SELECT id FROM cashflow_categories WHERE company_id = :companyId FOR UPDATE',
                ['companyId' => $plan['companyId']],
            );

            $plan = $this->plan($plan['companyId']);
            if ([] !== $plan['conflicts']) {
                throw new \DomainException('После блокировки данных обнаружены конфликты; компания не изменена.');
            }

            foreach ($plan['categories'] as $code => $category) {
                $params = [
                    'id' => $category['id'],
                    'companyId' => $plan['companyId'],
                    'parentId' => $category['parentId'],
                    'name' => $category['name'],
                    'sort' => $category['sort'],
                    'code' => $code,
                    'flowKind' => $category['flowKind'],
                ];

                if ($category['create']) {
                    $this->connection->executeStatement(<<<'SQL'
                        INSERT INTO cashflow_categories
                            (id, company_id, parent_id, name, description, status, sort, operation_type, allow_pl_document, pl_category_id, system_code, flow_kind, is_system)
                        VALUES
                            (:id, :companyId, :parentId, :name, NULL, 'active', :sort, NULL, FALSE, NULL, :code, :flowKind, TRUE)
                        SQL, $params);
                } else {
                    $this->connection->executeStatement(<<<'SQL'
                        UPDATE cashflow_categories
                        SET parent_id = :parentId,
                            name = :name,
                            sort = :sort,
                            system_code = :code,
                            flow_kind = :flowKind,
                            is_system = TRUE
                        WHERE id = :id AND company_id = :companyId
                        SQL, $params);
                }
            }

            $operatingId = $plan['categories'][CashflowCategory::CODE_OPERATING]['id'];
            foreach ($plan['rootsToMove'] as $rootId) {
                $this->connection->executeStatement(
                    'UPDATE cashflow_categories SET parent_id = :operatingId WHERE id = :rootId AND company_id = :companyId',
                    ['operatingId' => $operatingId, 'rootId' => $rootId, 'companyId' => $plan['companyId']],
                );
                $this->connection->executeStatement(<<<'SQL'
                    WITH RECURSIVE subtree AS (
                        SELECT id FROM cashflow_categories WHERE id = :rootId AND company_id = :companyId
                        UNION ALL
                        SELECT child.id
                        FROM cashflow_categories child
                        INNER JOIN subtree parent ON child.parent_id = parent.id
                    )
                    UPDATE cashflow_categories
                    SET flow_kind = :flowKind
                    WHERE id IN (SELECT id FROM subtree)
                    SQL, [
                    'rootId' => $rootId,
                    'companyId' => $plan['companyId'],
                    'flowKind' => CashflowFlowKind::OPERATING->value,
                ]);
            }
        });
    }
}
