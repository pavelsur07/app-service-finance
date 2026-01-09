<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Enum\PlNature;

final class PlNatureResolver
{
    public function forOperation(DocumentOperation $op): ?PlNature
    {
        $category = method_exists($op, 'getPlCategory')
            ? $op->getPlCategory()
            : (method_exists($op, 'getCategory') ? $op->getCategory() : null);

        return $category?->nature();
    }

    public function forDocument(Document $doc): PlNature|string
    {
        $hasIncome = false;
        $hasExpense = false;

        foreach ($doc->getOperations() as $operation) {
            $nature = $this->forOperation($operation);

            if (PlNature::INCOME === $nature) {
                $hasIncome = true;
            }

            if (PlNature::EXPENSE === $nature) {
                $hasExpense = true;
            }

            if ($hasIncome && $hasExpense) {
                return 'MIXED';
            }
        }

        if (!$hasIncome && !$hasExpense) {
            return 'UNKNOWN';
        }

        return $hasIncome ? PlNature::INCOME : PlNature::EXPENSE;
    }
}
