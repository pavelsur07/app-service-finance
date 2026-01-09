<?php

namespace App\Cash\Service\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Entity\CashTransaction;
use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
use App\Enum\DocumentType;
use Ramsey\Uuid\Uuid;

class CashTransactionToDocumentService
{
    /**
     * Создаёт документ ОПиУ на полный доступный остаток транзакции ДДС.
     *
     * @param CashTransaction $transaction
     *
     * @return Document
    */
    public function createFromCashTransaction(CashTransaction $transaction): Document
    {
        $remaining = $transaction->getRemainingAmount();
        $transaction->assertCanAllocateAmount($remaining);

        return $this->createDocument($transaction, $remaining);
    }

    /**
     * Создаёт документ ОПиУ на заданную сумму в пределах остатка транзакции ДДС.
     *
     * @param CashTransaction $transaction
     * @param float           $amount
     *
     * @return Document
     */
    public function createWithCustomAmount(CashTransaction $transaction, float $amount): Document
    {
        $transaction->assertCanAllocateAmount($amount);

        return $this->createDocument($transaction, $amount);
    }

    /**
     * Создаёт операцию документа из транзакции ДДС.
     *
     * @param CashTransaction $transaction
     *
     * @return DocumentOperation
     */
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

    private function createDocument(CashTransaction $transaction, float $amount): Document
    {
        $category = $transaction->getCashflowCategory();
        if (!$category instanceof CashflowCategory) {
            throw new \DomainException('Для транзакции не задана категория ДДС.');
        }

        if (!$category->isAllowPlDocument()) {
            throw new \DomainException('Для выбранной категории ДДС запрещено создавать документы ОПиУ.');
        }

        $plCategory = $this->resolvePlCategoryForCashflowCategory($category);
        if (!$plCategory instanceof PLCategory) {
            throw new \DomainException('Не настроена категория ОПиУ для выбранной категории ДДС.');
        }

        $document = new Document(Uuid::uuid4()->toString(), $transaction->getCompany());
        $document
            ->setDate($transaction->getOccurredAt())
            ->setType(DocumentType::CASHFLOW_EXPENSE)
            ->setCounterparty($transaction->getCounterparty())
            ->setCashTransaction($transaction);

        $operation = new DocumentOperation();
        $operation
            ->setAmount(number_format($amount, 2, '.', ''))
            ->setCounterparty($transaction->getCounterparty())
            ->setCategory($plCategory);

        $document->addOperation($operation);
        $transaction->addDocument($document);

        return $document;
    }
}
