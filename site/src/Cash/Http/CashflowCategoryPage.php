<?php

declare(strict_types=1);

namespace App\Cash\Http;

use App\Cash\Entity\Transaction\CashflowCategory;

final readonly class CashflowCategoryPage
{
    /** @return array<string, mixed> */
    public function serializeCategory(CashflowCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'description' => $category->getDescription(),
            'status' => $category->getStatus()->value,
            'sort' => $category->getSort(),
            'parentId' => $category->getParent()?->getId(),
            'operationType' => $category->getOperationType()?->value,
            'allowPlDocument' => $category->isAllowPlDocument(),
            'plCategoryId' => $category->getPlCategory()?->getId(),
            'systemCode' => $category->getSystemCode(),
            'code' => $category->getCode(),
            'flowKind' => $category->getFlowKind()->value,
            'isSystem' => $category->isSystem(),
            'children' => array_map(
                fn (CashflowCategory $child): array => $this->serializeCategory($child),
                $category->getChildren()->toArray(),
            ),
        ];
    }

    /**
     * @param array<CashflowCategory> $categories
     *
     * @return list<CashflowCategory>
     */
    public function regularParentChoices(array $categories, ?CashflowCategory $current = null): array
    {
        return array_values(array_filter(
            $categories,
            static fn (CashflowCategory $candidate): bool => $candidate->acceptsRegularChildren()
                && (null === $current || ($candidate !== $current && !$candidate->isDescendantOf($current))),
        ));
    }
}
