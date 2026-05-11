<?php

declare(strict_types=1);

namespace App\Cash\Facade;

use App\Cash\Application\DTO\CreateCashTransactionCommand;
use App\Cash\Application\DTO\CreateCashTransactionResult;
use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Transaction\CashTransactionService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class CashFacade
{
    public function __construct(
        private CashTransactionService $cashTransactionService,
        private CashTransactionRepository $cashTransactionRepository,
    ) {}

    public function createTransaction(CreateCashTransactionCommand $command): CreateCashTransactionResult
    {
        $dto = new CashTransactionDTO();
        $dto->companyId = $command->companyId;
        $dto->moneyAccountId = $command->moneyAccountId;
        $dto->direction = $command->direction;
        $dto->amount = $command->amount;
        $dto->currency = $command->currency;
        $dto->occurredAt = $command->occurredAt;
        $dto->description = $command->description;
        $dto->counterpartyId = $command->counterpartyId;
        $dto->cashflowCategoryId = $command->cashflowCategoryId;
        $dto->projectDirectionId = $command->projectDirectionId;
        $dto->importSource = $command->importSource;
        $dto->externalId = $command->externalId;
        $dto->dedupeHash = $command->dedupeHash;
        $dto->rawData = $command->rawData;

        $existing = $this->findExistingByImport($command);
        if (null !== $existing) {
            return new CreateCashTransactionResult((string) $existing->getId(), false, true);
        }

        try {
            $transaction = $this->cashTransactionService->add($dto);

            return new CreateCashTransactionResult((string) $transaction->getId(), true, false);
        } catch (UniqueConstraintViolationException $e) {
            if (null === $command->importSource || null === $command->externalId) {
                throw $e;
            }

            $existingId = $this->cashTransactionRepository->findAnyIdByCompanyImportSourceExternalIdDbal(
                $command->companyId,
                $command->importSource,
                $command->externalId,
            );

            if (null !== $existingId) {
                return new CreateCashTransactionResult($existingId, false, true);
            }

            throw $e;
        }
    }

    private function findExistingByImport(CreateCashTransactionCommand $command): ?\App\Cash\Entity\Transaction\CashTransaction
    {
        if (null === $command->importSource || null === $command->externalId) {
            return null;
        }

        return $this->cashTransactionRepository->findAnyByCompanyImportSourceExternalId(
            $command->companyId,
            $command->importSource,
            $command->externalId,
        );
    }

}
