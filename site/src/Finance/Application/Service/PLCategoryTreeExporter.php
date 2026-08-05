<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Entity\PLCategory;

/**
 * Выгрузка дерева категорий ОПиУ: сущности → узлы-источники → payload файла.
 *
 * Единственное место сериализации формата обмена. Набор полей обязан совпадать
 * с тем, что применяет ImportPLCategoryTreeAction::applyFields(): выгруженный
 * файл должен быть полноценным источником импорта, иначе перенос между
 * аккаунтами молча теряет часть настроек строки P&L.
 */
final class PLCategoryTreeExporter
{
    public const FORMAT_VERSION = 1;

    /**
     * @param PLCategory[] $dfsTree дерево в DFS pre-order (PLCategoryRepository::findTreeByCompany())
     *
     * @return list<PLCategoryTreeNode>
     */
    public function fromEntities(array $dfsTree): array
    {
        /** @var array<string, PLCategoryTreeNode> $nodesById */
        $nodesById = [];
        $nodes = [];

        foreach ($dfsTree as $category) {
            $id = (string) $category->getId();
            $parentId = $category->getParent()?->getId();

            // Родитель обязан встретиться раньше потомка: список собирается
            // обходом от корней. Если это не так — дерево пришло не в том
            // порядке, и молча делать узел корневым нельзя: это переставило бы
            // категории в целевой компании.
            $parent = null;
            if (null !== $parentId) {
                $parent = $nodesById[(string) $parentId]
                    ?? throw new \LogicException(sprintf('Родитель категории "%s" не найден среди уже обойдённых узлов — дерево не в DFS pre-order.', $category->getName()));
            }

            $node = new PLCategoryTreeNode(
                key: $id,
                parent: $parent,
                name: $category->getName(),
                code: $category->getCode(),
                type: $category->getType(),
                format: $category->getFormat(),
                flow: $category->getFlow(),
                expenseType: $category->getExpenseType(),
                weightInParent: $category->getWeightInParent(),
                isVisible: $category->isVisible(),
                formula: $category->getFormula(),
                calcOrder: $category->getCalcOrder(),
                sortOrder: $category->getSortOrder(),
            );

            $nodesById[$id] = $node;
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * @param list<PLCategoryTreeNode> $nodes
     *
     * @return array<string, mixed>
     */
    public function toFilePayload(array $nodes, string $companyName, \DateTimeImmutable $exportedAt): array
    {
        return [
            'version' => self::FORMAT_VERSION,
            'exportedAt' => $exportedAt->format(\DATE_ATOM),
            'company' => $companyName,
            'categories' => $this->nestChildren($nodes, null),
        ];
    }

    /**
     * @param list<PLCategoryTreeNode> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function nestChildren(array $nodes, ?string $parentKey): array
    {
        // ponytail: O(n²) обход — дерево ОПиУ ограничено 5 уровнями и сотнями
        // узлов. Если вырастет — сгруппировать узлы по parent-ключу заранее.
        $result = [];

        foreach ($nodes as $node) {
            if ($node->parent?->key !== $parentKey) {
                continue;
            }

            $result[] = [
                'name' => $node->name,
                'code' => $node->code,
                'type' => $node->type->value,
                'format' => $node->format->value,
                'flow' => $node->flow->value,
                'expenseType' => $node->expenseType->value,
                'weightInParent' => $node->weightInParent,
                'isVisible' => $node->isVisible,
                'formula' => $node->formula,
                'calcOrder' => $node->calcOrder,
                'sortOrder' => $node->sortOrder,
                'children' => $this->nestChildren($nodes, $node->key),
            ];
        }

        return $result;
    }
}
