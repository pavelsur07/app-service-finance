<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Transaction\CashTransactionToDocumentService;
use App\Finance\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Создаёт документ ОПиУ на весь остаток транзакции ДДС и обновляет регистр ОПиУ в одной
 * транзакции БД под блокировкой строки транзакции ДДС (было
 * CashTransactionToDocumentService::createPnlDocumentFromTransaction). Параллельный или
 * повторный запрос ждёт commit первого и получает отказ «остатка нет», а не второй документ.
 */
final class CreatePnlDocumentFromTransactionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionToDocumentService $documents,
        private readonly CashTransactionRepository $transactions,
    ) {
    }

    public function __invoke(CashTransaction $transaction): Document
    {
        $companyId = (string) $transaction->getCompany()->getId();
        $transactionId = (string) $transaction->getId();

        $document = $this->entityManager->wrapInTransaction(function () use ($companyId, $transactionId): Document {
            // Вызывающий проверял остаток до блокировки — сбрасываем identity map и читаем
            // транзакцию с документами заново под FOR UPDATE.
            $this->entityManager->clear();
            $transaction = $this->transactions->findOneByIdAndCompanyIdForUpdate($transactionId, $companyId)
                ?? throw new \DomainException('Транзакция ДДС не найдена.');

            $document = $this->documents->createFromCashTransaction($transaction);

            $this->entityManager->persist($document);
            $this->entityManager->flush();

            $this->documents->updatePlRegister($document);

            return $document;
        });

        $this->documents->invalidatePlRegisterCache($companyId);

        return $document;
    }
}
