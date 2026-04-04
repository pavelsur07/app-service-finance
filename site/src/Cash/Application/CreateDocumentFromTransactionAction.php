<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\DTO\CreateDocumentCommand;
use App\Cash\Application\DTO\CreateDocumentResult;
use App\Cash\Entity\Transaction\CashTransaction;
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

        $category = $tx->getCashflowCategory();
        $plCategory = $category?->getPlCategory();
        $hasPLCategory = ($plCategory !== null);

        $amount = number_format($tx->getRemainingAmount(), 2, '.', '');

        if ($hasPLCategory) {
            $command = new CreateDocumentCommand(
                cashTransactionId: $tx->getId(),
                occurredAt: $tx->getOccurredAt(),
                amount: $amount,
                counterpartyId: $tx->getCounterparty()?->getId(),
                projectDirectionId: $tx->getProjectDirection()?->getId(),
                plCategoryId: $plCategory->getId(),
                createdWithViolation: false,
            );

            $documentId = $this->financeFacade->createDocumentFromCashTransaction(
                $tx->getCompany()->getId(),
                $command,
            );

            $this->entityManager->flush();

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
                    . 'Документ будет создан с частично заполненными данными — '
                    . 'дата, сумма, контрагент, проект. '
                    . 'Категорию ОПиУ нужно будет указать вручную.',
            );
        }

        $command = new CreateDocumentCommand(
            cashTransactionId: $tx->getId(),
            occurredAt: $tx->getOccurredAt(),
            amount: $amount,
            counterpartyId: $tx->getCounterparty()?->getId(),
            projectDirectionId: $tx->getProjectDirection()?->getId(),
            plCategoryId: null,
            createdWithViolation: true,
        );

        $documentId = $this->financeFacade->createDocumentFromCashTransaction(
            $tx->getCompany()->getId(),
            $command,
        );

        $tx->markAsHavingViolatedDocument();

        $this->entityManager->flush();

        return new CreateDocumentResult(
            needsConfirmation: false,
            documentId: $documentId,
            hasViolation: true,
            warningMessage: '',
        );
    }
}
