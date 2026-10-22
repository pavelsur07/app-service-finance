<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\DTO\CreateDocumentCommand;
use App\Cash\Application\DTO\CreateDocumentResult;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Finance\Facade\FinanceFacade;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Документ ОПиУ на остаток транзакции ДДС. Проверка остатка, документ и пересчёт регистра — одна
 * транзакция под блокировкой строки транзакции ДДС: параллельный или повторный запрос ждёт
 * commit первого и видит остаток 0, а не создаёт второй документ на тот же остаток.
 */
final class CreateDocumentFromTransactionAction
{
    public function __construct(
        private readonly FinanceFacade $financeFacade,
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionRepository $transactions,
    ) {
    }

    public function __invoke(CashTransaction $tx, bool $confirmed): CreateDocumentResult
    {
        $companyId = (string) $tx->getCompany()->getId();
        $transactionId = (string) $tx->getId();

        return $this->entityManager->wrapInTransaction(function () use ($companyId, $transactionId, $confirmed): CreateDocumentResult {
            // Остаток считается по документам транзакции. Вызывающий уже загрузил транзакцию и,
            // возможно, её документы до блокировки — сбрасываем identity map и читаем заново
            // под FOR UPDATE, иначе ожидавший запрос проверит остаток по устаревшей коллекции.
            $this->entityManager->clear();
            $tx = $this->transactions->findOneByIdAndCompanyIdForUpdate($transactionId, $companyId)
                ?? throw new \DomainException('Транзакция ДДС не найдена.');

            return $this->create($tx, $confirmed);
        });
    }

    private function create(CashTransaction $tx, bool $confirmed): CreateDocumentResult
    {
        if ($tx->isTransfer()) {
            throw new \DomainException('Для переводов нельзя создать документ ОПиУ.');
        }

        if (!$tx->getRemainingMoney()->isPositive()) {
            throw new \DomainException('Транзакция уже полностью разнесена.');
        }

        $category = $tx->getSingleSplitCategory();
        if (null !== $category && !$category->isAllowPlDocument()) {
            throw new \DomainException('Для этой категории ДДС создание документов ОПиУ запрещено.');
        }
        $plCategory = $category?->getPlCategory();
        $hasPLCategory = (null !== $plCategory);

        $amount = $tx->getRemainingMoney()->toDecimalString();

        if ($hasPLCategory) {
            $command = new CreateDocumentCommand(
                cashTransactionId: $tx->getId(),
                occurredAt: $tx->getOccurredAt(),
                amount: $amount,
                counterpartyId: $tx->getCounterparty()?->getId(),
                projectDirectionId: $tx->getProjectDirection()?->getId(),
                responsibilityCenterId: $tx->getResponsibilityCenterId(),
                plCategoryId: $plCategory->getId(),
                createdWithViolation: false,
            );

            $documentId = $this->financeFacade->createDocumentFromCashTransaction(
                $tx->getCompany()->getId(),
                $tx,
                $command,
            );

            $this->entityManager->flush();
            $this->financeFacade->updatePLRegisterForDocument($documentId);

            return new CreateDocumentResult(
                needsConfirmation: false,
                documentId: $documentId,
                hasViolation: false,
                warningMessage: '',
            );
        }

        if (!$confirmed) {
            return new CreateDocumentResult(
                needsConfirmation: true,
                documentId: null,
                hasViolation: false,
                warningMessage: 'У категории ДДС не задана категория ОПиУ. '
                    .'Документ будет создан с частично заполненными данными — '
                    .'дата, сумма, контрагент, проект. '
                    .'Категорию ОПиУ нужно будет указать вручную.',
            );
        }

        $command = new CreateDocumentCommand(
            cashTransactionId: $tx->getId(),
            occurredAt: $tx->getOccurredAt(),
            amount: $amount,
            counterpartyId: $tx->getCounterparty()?->getId(),
            projectDirectionId: $tx->getProjectDirection()?->getId(),
            responsibilityCenterId: $tx->getResponsibilityCenterId(),
            plCategoryId: null,
            createdWithViolation: true,
        );

        $documentId = $this->financeFacade->createDocumentFromCashTransaction(
            $tx->getCompany()->getId(),
            $tx,
            $command,
        );

        $this->entityManager->flush();
        $this->financeFacade->updatePLRegisterForDocument($documentId);

        return new CreateDocumentResult(
            needsConfirmation: false,
            documentId: $documentId,
            hasViolation: true,
            warningMessage: '',
        );
    }
}
