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
            DocumentType::SALES_INVOICE,
            DocumentType::DELIVERY_NOTE,
            DocumentType::SERVICE_ACT,
            DocumentType::COMMISSION_REPORT,
            DocumentType::MARKETPLACE_REPORT,
            DocumentType::CASH_RECEIPT,
            DocumentType::BANK_STATEMENT,
            DocumentType::FX_REVALUATION_ACT => PlNature::INCOME,

            DocumentType::SUPPLIER_INVOICE,
            DocumentType::MATERIAL_WRITE_OFF_ACT,
            DocumentType::MANUFACTURING_ACT,
            DocumentType::COST_ALLOCATION,
            DocumentType::AD_ACT,
            DocumentType::RENT_ACT,
            DocumentType::UTILITIES_ACT,
            DocumentType::BANK_FEES_ACT,
            DocumentType::PAYROLL_SHEET,
            DocumentType::ADVANCE_REPORT,
            DocumentType::LOAN_INTEREST_STATEMENT => PlNature::EXPENSE,

            DocumentType::OTHER => null,
        };
    }
}
