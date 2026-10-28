<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;

/**
 * Дерево статей ОПиУ для виджета «Категория ОПиУ» (`partials/_dds_tree_picker.html.twig`).
 *
 * Группа — статья с дочерними, лист — без них; id листа совпадает с value
 * option в select формы. Направление листа (`flow`) — его собственный `PLFlow`:
 * регистр ОПиУ берёт знак из статьи операции, а не из родителя; `NONE`
 * (знак по сумме) подходит обеим сторонам (`both`).
 *
 * Статьи передаёт вызывающий (`PLCategoryRepository::findTreeByCompany()` своей
 * компании, порядок дерева) — построитель в БД не ходит и связи `children`
 * не читает, чтобы не догружать их по статье.
 *
 * @phpstan-type TreeNode array{id: string, name: string, children?: list<mixed>, flow?: 'in'|'out'|'both'}
 */
final readonly class PLCategoryTreeBuilder
{
    /**
     * @param iterable<PLCategory> $categories статьи одной компании (порядок дерева — порядок вывода)
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
     * @param array<string, PLCategory> $byId
     * @param array<string, list<string>> $childIds
     *
     * @return TreeNode
     */
    private function node(string $id, array $byId, array $childIds): array
    {
        $category = $byId[$id];
        if (!isset($childIds[$id])) {
            return ['id' => $id, 'name' => $category->getName(), 'flow' => self::flow($category->getFlow())];
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
    private static function flow(PLFlow $flow): string
    {
        return match ($flow) {
            PLFlow::INCOME => 'in',
            PLFlow::EXPENSE => 'out',
            PLFlow::NONE => 'both',
        };
    }
}
