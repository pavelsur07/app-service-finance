<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Service\Transaction\CashTransactionToDocumentService;
use App\Finance\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Создаёт документ ОПиУ на весь остаток транзакции ДДС и обновляет регистр ОПиУ
 * в одной транзакции БД (было CashTransactionToDocumentService::createPnlDocumentFromTransaction).
 */
final class CreatePnlDocumentFromTransactionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionToDocumentService $documents,
    ) {
    }

    public function __invoke(CashTransaction $transaction): Document
    {
        $this->entityManager->beginTransaction();
        try {
            $document = $this->documents->createFromCashTransaction($transaction);

            $this->entityManager->persist($document);
            $this->entityManager->flush();

            $this->documents->updatePlRegister($document);
            $this->entityManager->commit();

            return $document;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }
}
