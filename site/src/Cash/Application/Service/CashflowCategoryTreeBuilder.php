<?php

declare(strict_types=1);

namespace App\Cash\Application\Service;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;

/**
 * Дерево статей ДДС для виджета выбора (`partials/_dds_tree_picker.html.twig`).
 *
 * Группа — статья с дочерними, лист — без них; id листа совпадает с value
 * option в select формы. Направление листа (`flow`) берётся из данных, а не
 * угадывается: ближайший вверх по дереву `operationType` или системная
 * «Поступления»/«Выбытия» технических операций. Где направление не задано,
 * статья подходит обоим (`both`).
 *
 * @phpstan-type TreeNode array{id: string, name: string, children?: list<mixed>, flow?: 'in'|'out'|'both'}
 */
final readonly class CashflowCategoryTreeBuilder
{
    /**
     * @param iterable<CashflowCategory> $categories статьи одной компании (порядок дерева — порядок вывода)
     *
     * @return list<TreeNode>
     */
    public function build(iterable $categories): array
    {
        $byId = [];
        $childIds = [];
        $rootIds = [];
        foreach ($categories as $category) {
            $id = (string) $category->getId();
            $byId[$id] = $category;
            $parentId = $category->getParent()?->getId();
            if (null === $parentId) {
                $rootIds[] = $id;
            } else {
                $childIds[(string) $parentId][] = $id;
            }
        }

        // Статья, чей родитель не попал в список, показывается на верхнем уровне, а не теряется.
        foreach ($childIds as $parentId => $ids) {
            if (!isset($byId[$parentId])) {
                array_push($rootIds, ...$ids);
                unset($childIds[$parentId]);
            }
        }

        return array_map(fn (string $id): array => $this->node($id, $byId, $childIds), $rootIds);
    }

    /**
     * @param array<string, CashflowCategory> $byId
     * @param array<string, list<string>> $childIds
     *
     * @return TreeNode
     */
    private function node(string $id, array $byId, array $childIds): array
    {
        $category = $byId[$id];
        if (!isset($childIds[$id])) {
            return ['id' => $id, 'name' => $category->getName(), 'flow' => self::flow($category)];
        }

        return [
            'id' => $id,
            'name' => $category->getName(),
            'children' => array_map(fn (string $childId): array => $this->node($childId, $byId, $childIds), $childIds[$id]),
        ];
    }

    /**
     * @return 'in'|'out'|'both'
     */
    private static function flow(CashflowCategory $category): string
    {
        for ($node = $category; null !== $node; $node = $node->getParent()) {
            $flow = match ($node->getSystemCode()) {
                CashflowCategory::CODE_TECHNICAL_IN => 'in',
                CashflowCategory::CODE_TECHNICAL_OUT => 'out',
                default => match ($node->getOperationType()) {
                    PaymentPlanType::INFLOW => 'in',
                    PaymentPlanType::OUTFLOW => 'out',
                    PaymentPlanType::TRANSFER => 'both',
                    null => null,
                },
            };
            if (null !== $flow) {
                return $flow;
            }
        }

        return 'both';
    }
}
