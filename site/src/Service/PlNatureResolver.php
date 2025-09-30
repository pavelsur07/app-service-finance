<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Enum\DocumentType;
use App\Enum\PlNature;

final class PlNatureResolver
{
    public function forOperation(DocumentOperation $op): ?PlNature
    {
        $category = method_exists($op, 'getPlCategory')
            ? $op->getPlCategory()
            : (method_exists($op, 'getCategory') ? $op->getCategory() : null);

        if ($category) {
            return $category->nature();
        }

        $document = $op->getDocument();

        return $document ? $this->byDocumentType($document->getType()) : null;
    }

    /**
     * @return PlNature|string
     */
    public function forDocument(Document $doc): PlNature|string
    {
        $hasIncome = false;
        $hasExpense = false;

        foreach ($doc->getOperations() as $operation) {
            $nature = $this->forOperation($operation);

            if ($nature === PlNature::INCOME) {
                $hasIncome = true;
            }

            if ($nature === PlNature::EXPENSE) {
                $hasExpense = true;
            }

            if ($hasIncome && $hasExpense) {
                return 'MIXED';
            }
        }

        if (!$doc->getOperations()->count()) {
            return $this->byDocumentType($doc->getType()) ?? 'UNKNOWN';
        }

        if (!$hasIncome && !$hasExpense) {
            return 'UNKNOWN';
        }

        return $hasIncome ? PlNature::INCOME : PlNature::EXPENSE;
    }

    private function byDocumentType(DocumentType $type): ?PlNature
    {
        return match ($type) {
            DocumentType::SERVICE_ACT,
            DocumentType::SALES_DELIVERY_NOTE,
            DocumentType::COMMISSION_REPORT => PlNature::INCOME,

            DocumentType::PURCHASE_INVOICE,
            DocumentType::ACCEPTANCE_ACT,
            DocumentType::WRITE_OFF_ACT,
            DocumentType::INVENTORY_SHEET,
            DocumentType::LOAN_AND_SCHEDULE,
            DocumentType::PAYROLL_ACCRUAL,
            DocumentType::DEPRECIATION,
            DocumentType::TAXES_AND_CONTRIBUTIONS,
            DocumentType::FX_PENALTIES => PlNature::EXPENSE,

            DocumentType::SALES_OR_PURCHASE_RETURN,
            DocumentType::OTHER => null,
        };
    }
}
