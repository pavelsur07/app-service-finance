<?php

namespace App\Service;

use App\Entity\PLCategory;

final class PLCategoryTree
{
    /**
     * @param PLCategory[] $flat
     */
    public static function build(array $flat): array
    {
        $byId = [];
        foreach ($flat as $node) {
            $byId[$node->getId()] = [
                'id' => $node->getId(),
                'name' => $node->getName(),
                'code' => $node->getCode(),
                'type' => $node->getType()->value,
                'format' => $node->getFormat()->value,
                'weightInParent' => $node->getWeightInParent(),
                'isVisible' => $node->isVisible(),
                'sortOrder' => $node->getSortOrder(),
                'parentId' => $node->getParent()?->getId(),
                'children' => [],
                'entity' => $node,
            ];
        }

        $roots = [];
        foreach ($byId as $id => &$node) {
            $parentId = $node['parentId'];
            if ($parentId && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }

        return $roots;
    }
}
