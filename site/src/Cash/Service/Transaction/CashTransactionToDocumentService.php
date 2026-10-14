<?php

declare(strict_types=1);

namespace App\Cash\Service\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Company\Entity\ProjectDirection;
use App\Company\Repository\ProjectDirectionRepository;
use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\DocumentType;
use App\Shared\Domain\ValueObject\Money;
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
        $remaining = $transaction->getRemainingMoney();
        $transaction->assertCanAllocate($remaining);

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
     * Создаёт операцию документа из транзакции ДДС.
     */
    public function createOperationFromTransaction(CashTransaction $transaction): DocumentOperation
    {
        $operation = new DocumentOperation();
        $operation->setAmount($transaction->getAmount());
        $operation->setCounterparty($transaction->getCounterparty());
        $operation->setProjectDirection($this->resolveProjectDirection($transaction));
        $operation->setResponsibilityCenterId($transaction->getResponsibilityCenterId());

        $category = $transaction->getSingleSplitCategory();
        if ($category instanceof CashflowCategory) {
            $operation->setCategory($this->resolvePlCategoryForCashflowCategory($category));
        }

        return $operation;
    }

    private function resolvePlCategoryForCashflowCategory(CashflowCategory $category): ?PLCategory
    {
        return $category->getPlCategory();
    }

    private function createDocument(CashTransaction $transaction, Money $amount): Document
    {
        $category = $transaction->getSingleSplitCategory();
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
            ->setResponsibilityCenterId($transaction->getResponsibilityCenterId())
            ->setCashTransaction($transaction);

        $operation = new DocumentOperation();
        $operation
            ->setAmount($amount->toDecimalString())
            ->setCounterparty($transaction->getCounterparty())
            ->setCategory($plCategory)
            ->setProjectDirection($projectDirection)
            ->setResponsibilityCenterId($transaction->getResponsibilityCenterId());

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
