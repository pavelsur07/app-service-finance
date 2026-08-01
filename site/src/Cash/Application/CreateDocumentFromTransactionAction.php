<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\DTO\CreateDocumentCommand;
use App\Cash\Application\DTO\CreateDocumentResult;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Finance\Facade\FinanceFacade;
use Doctrine\ORM\EntityManagerInterface;

final class CreateDocumentFromTransactionAction
{
    public function __construct(
        private readonly FinanceFacade $financeFacade,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CashTransaction $tx, bool $confirmed): CreateDocumentResult
    {
        if ($tx->isTransfer()) {
            throw new \DomainException('Для переводов нельзя создать документ ОПиУ.');
        }

        if ($tx->getRemainingAmount() <= 0) {
            throw new \DomainException('Транзакция уже полностью разнесена.');
        }

        $category = self::resolveSplitCategory($tx);
        if (null !== $category && !$category->isAllowPlDocument()) {
            throw new \DomainException('Для этой категории ДДС создание документов ОПиУ запрещено.');
        }
        $plCategory = $category?->getPlCategory();
        $hasPLCategory = (null !== $plCategory);

        $amount = number_format($tx->getRemainingAmount(), 2, '.', '');

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

    /**
     * Категория берётся из единственной строки разбивки. Решение D1 запрещает разбивать
     * транзакцию по категориям с allowPlDocument, поэтому здесь строка всегда одна и
     * семантика та же, что была у скалярной колонки.
     */
    private static function resolveSplitCategory(CashTransaction $transaction): ?CashflowCategory
    {
        $split = $transaction->getSplits()->first();

        return $split instanceof CashTransactionSplit ? $split->getCashflowCategory() : null;
    }
}
