<?php

declare(strict_types=1);

namespace App\Cash\Facade;

use App\Cash\Application\DTO\CreateCashTransactionCommand;
use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Service\Transaction\CashTransactionService;

final readonly class CashFacade
{
    public function __construct(
        private CashTransactionService $cashTransactionService,
    ) {}

    public function createTransaction(CreateCashTransactionCommand $command): string
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

        $transaction = $this->cashTransactionService->add($dto);

        return (string) $transaction->getId();
    }
}
