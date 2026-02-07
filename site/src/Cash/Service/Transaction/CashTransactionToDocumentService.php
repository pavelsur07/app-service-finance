<?php

namespace App\Cash\Service\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
use App\Entity\ProjectDirection;
use App\Enum\DocumentType;
use App\Repository\ProjectDirectionRepository;
use App\Service\PLRegisterUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

class CashTransactionToDocumentService
{
    public function __construct(
        private EntityManagerInterface $em,
        private PLRegisterUpdater $plRegisterUpdater,
        private ProjectDirectionRepository $projectDirections,
    ) {
    }

    /**
     * Создаёт документ ОПиУ на полный доступный остаток транзакции ДДС.
     */
    public function createFromCashTransaction(CashTransaction $transaction): Document
    {
        $remaining = $transaction->getRemainingAmount();
        $transaction->assertCanAllocateAmount($remaining);

        return $this->createDocument($transaction, $remaining);
    }

    public function createPnlDocumentFromTransaction(CashTransaction $transaction): Document
    {
        $this->em->beginTransaction();
        try {
            $document = $this->createFromCashTransaction($transaction);

            $this->em->persist($document);
            $this->em->flush();

            $this->plRegisterUpdater->updateForDocument($document);
            $this->em->commit();

            return $document;
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    /**
     * Создаёт документ ОПиУ на заданную сумму в пределах остатка транзакции ДДС.
     */
    public function createWithCustomAmount(CashTransaction $transaction, float $amount): Document
    {
        $transaction->assertCanAllocateAmount($amount);

        return $this->createDocument($transaction, $amount);
    }

    /**
     * Создаёт операцию документа из транзакции ДДС.
     */
    public function createOperationFromTransaction(CashTransaction $transaction): DocumentOperation
    {
        $operation = new DocumentOperation();
        $operation->setAmount($transaction->getAmount());
        $operation->setCounterparty($transaction->getCounterparty());
        $operation->setProjectDirection($this->resolveProjectDirection($transaction));

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

        $projectDirection = $this->resolveProjectDirection($transaction);
        $document = new Document(Uuid::uuid4()->toString(), $transaction->getCompany());
        $document
            ->setDate($transaction->getOccurredAt())
            ->setDescription($transaction->getDescription())
            ->setType(DocumentType::CASHFLOW_EXPENSE)
            ->setCounterparty($transaction->getCounterparty())
            ->setProjectDirection($projectDirection)
            ->setCashTransaction($transaction);

        $operation = new DocumentOperation();
        $operation
            ->setAmount(number_format($amount, 2, '.', ''))
            ->setCounterparty($transaction->getCounterparty())
            ->setCategory($plCategory)
            ->setProjectDirection($projectDirection);

        $document->addOperation($operation);
        $transaction->addDocument($document);

        return $document;
    }

    private function resolveProjectDirection(CashTransaction $transaction): ProjectDirection
    {
        $projectDirection = $transaction->getProjectDirection();
        if ($projectDirection instanceof ProjectDirection) {
            return $projectDirection;
        }

        $defaultProject = $this->projectDirections->findDefaultForCompany($transaction->getCompany());
        if (!$defaultProject instanceof ProjectDirection) {
            throw new \DomainException('Не найден системный проект "Основной".');
        }

        return $defaultProject;
    }
}
