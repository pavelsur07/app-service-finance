<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\UpsertFinancialTransactionCommand;
use App\Ingestion\Application\DTO\UpsertResult;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Exception\StaleTransactionUpdateException;
use App\Ingestion\Repository\FinancialTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UpsertFinancialTransactionAction
{
    public function __construct(
        private FinancialTransactionRepository $financialTransactionRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(UpsertFinancialTransactionCommand $command): ?UpsertResult
    {
        $mapped = $command->mapped;
        $transaction = $this->financialTransactionRepository->findByNaturalKey(
            $command->companyId,
            $command->source,
            $mapped->externalId,
            $mapped->type,
        );

        if (null === $transaction) {
            $transaction = new FinancialTransaction(
                companyId: $command->companyId,
                connectionRef: $command->connectionRef,
                shopRef: $command->shopRef,
                source: $command->source,
                externalId: $mapped->externalId,
                externalUpdatedAt: $mapped->externalUpdatedAt,
                operationGroupId: $mapped->operationGroupId,
                type: $mapped->type,
                direction: $mapped->direction,
                money: $mapped->money,
                occurredAt: $mapped->occurredAt,
                rawRecordId: $command->rawRecordId,
                orderRef: $mapped->orderRef,
                payoutRef: $mapped->payoutRef,
                counterpartyId: $command->counterpartyId,
                description: $mapped->description,
                sourceData: $mapped->sourceData,
                sourceTz: $mapped->sourceTz,
                listingId: $command->listingId,
                listingSku: $command->listingSku,
            );

            $this->entityManager->persist($transaction);
            $this->financialTransactionRepository->cache($transaction);

            return new UpsertResult(
                transactionId: $transaction->getId(),
                oldOccurredAt: null,
                newOccurredAt: $mapped->occurredAt,
                periodChanged: false,
            );
        }

        $oldOccurredAt = $transaction->getOccurredAt();

        try {
            $transaction->replaceFromNewerVersion(
                money: $mapped->money,
                type: $mapped->type,
                direction: $mapped->direction,
                occurredAt: $mapped->occurredAt,
                externalUpdatedAt: $mapped->externalUpdatedAt,
                orderRef: $mapped->orderRef,
                payoutRef: $mapped->payoutRef,
                counterpartyId: $command->counterpartyId,
                description: $mapped->description,
                sourceData: $mapped->sourceData,
                listingId: $command->listingId,
                listingSku: $command->listingSku,
            );
        } catch (StaleTransactionUpdateException) {
            return null;
        }

        return new UpsertResult(
            transactionId: $transaction->getId(),
            oldOccurredAt: $oldOccurredAt,
            newOccurredAt: $mapped->occurredAt,
            periodChanged: $oldOccurredAt != $mapped->occurredAt,
        );
    }
}
