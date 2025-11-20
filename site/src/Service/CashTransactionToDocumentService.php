<?php

namespace App\Service;

use App\Entity\CashTransaction;
use App\Entity\CashflowCategory;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;

class CashTransactionToDocumentService
{
    public function createOperationFromTransaction(CashTransaction $transaction): DocumentOperation
    {
        $operation = new DocumentOperation();
        $operation->setAmount($transaction->getAmount());
        $operation->setCounterparty($transaction->getCounterparty());

        $category = $transaction->getCashflowCategory();
        if ($category instanceof CashflowCategory) {
            $operation->setCategory($this->resolvePlCategoryForCashflowCategory($category));
        }

        return $operation;
    }

    private function resolvePlCategoryForCashflowCategory(CashflowCategory $category): ?PLCategory
    {
        return $category->getPlCategory();
    }
}
