<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\Command\RecordNormalizationIssueCommand;
use App\Ingestion\Application\Command\UpsertFinancialTransactionCommand;
use App\Ingestion\Application\DTO\MappedControlSum;
use App\Ingestion\Domain\Contract\RawRecordAwareControlSumMapperInterface;
use App\Ingestion\Domain\Event\AffectedPeriod;
use App\Ingestion\Domain\Event\NormalizationCompletedEvent;
use App\Ingestion\Domain\Service\MapperRegistry;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Exception\RawRecordNotFoundException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\CounterpartyRepository;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class NormalizeRawRecordAction
{
    public function __construct(
        private IngestRawRecordRepository $rawRecordRepository,
        private RawStorageFacade $rawStorageFacade,
        private MapperRegistry $mapperRegistry,
        private CounterpartyRepository $counterpartyRepository,
        private FinancialTransactionRepository $financialTransactionRepository,
        private UpsertFinancialTransactionAction $upsertFinancialTransactionAction,
        private RecordNormalizationIssueAction $recordNormalizationIssueAction,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(NormalizeRawRecordCommand $command): void
    {
        $event = null;
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $rawRecord = $this->rawRecordRepository->findByIdAndCompany($command->rawRecordId, $command->companyId);
            if (null === $rawRecord) {
                throw new RawRecordNotFoundException('Raw record not found for requested company.');
            }

            if (RawNormalizationStatus::DONE === $rawRecord->getNormalizationStatus()) {
                $connection->commit();

                return;
            }

            $mapper = $this->mapperRegistry->get($rawRecord->getSource(), $rawRecord->getResourceType());
            /** @var list<array<string, mixed>> $rows */
            $rows = array_values(iterator_to_array($this->rawStorageFacade->read($rawRecord->getId(), $command->companyId), false));

            try {
                $mappedTransactions = $mapper->map($rawRecord, $rows);
                $controlSums = $mapper instanceof RawRecordAwareControlSumMapperInterface
                    ? $mapper->controlSumForRawRecord($rawRecord, $rows)
                    : $mapper->controlSum($rows);
            } catch (\Throwable $exception) {
                ($this->recordNormalizationIssueAction)(new RecordNormalizationIssueCommand(
                    companyId: $command->companyId,
                    rawRecordId: $rawRecord->getId(),
                    operationGroupId: null,
                    kind: NormalizationIssueKind::MAPPER_FAILURE,
                    details: [
                        'exceptionClass' => $exception::class,
                        'message' => $exception->getMessage(),
                    ],
                ));
                $rawRecord->markNormalizationFailed();
                $this->entityManager->flush();
                $connection->commit();

                return;
            }

            $affectedPeriods = [];
            foreach ($mappedTransactions as $mappedTransaction) {
                $counterpartyId = null;
                if (null !== $mappedTransaction->counterpartyExternalKey) {
                    $counterparty = $this->counterpartyRepository->getOrCreate(
                        $command->companyId,
                        $rawRecord->getSource(),
                        $mappedTransaction->counterpartyExternalKey,
                        $mappedTransaction->counterpartyName ?? $mappedTransaction->counterpartyExternalKey,
                    );
                    $counterpartyId = $counterparty->getId();
                }

                $result = ($this->upsertFinancialTransactionAction)(new UpsertFinancialTransactionCommand(
                    companyId: $command->companyId,
                    connectionRef: $rawRecord->getConnectionRef(),
                    shopRef: $rawRecord->getShopRef(),
                    source: $rawRecord->getSource(),
                    mapped: $mappedTransaction,
                    rawRecordId: $rawRecord->getId(),
                    counterpartyId: $counterpartyId,
                ));

                if (null !== $result) {
                    $affectedPeriods[] = new AffectedPeriod(
                        shopRef: $rawRecord->getShopRef(),
                        oldOccurredAt: $result->oldOccurredAt,
                        newOccurredAt: $result->newOccurredAt,
                    );
                }
            }

            $this->entityManager->flush();
            $this->recordControlSumIssues($command->companyId, $rawRecord->getId(), $controlSums);
            $rawRecord->markNormalizationDone();
            $this->entityManager->flush();
            $connection->commit();

            $event = new NormalizationCompletedEvent(
                companyId: $command->companyId,
                rawRecordId: $rawRecord->getId(),
                affectedPeriods: $affectedPeriods,
            );
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }

        if (null !== $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * @param list<MappedControlSum> $controlSums
     */
    private function recordControlSumIssues(string $companyId, string $rawRecordId, array $controlSums): void
    {
        foreach ($controlSums as $controlSum) {
            $transactions = $this->financialTransactionRepository->findByOperationGroup($companyId, $controlSum->operationGroupId);
            $actualAmountMinor = 0;
            $hasCurrencyMismatch = false;

            foreach ($transactions as $transaction) {
                if ($transaction->getCurrency() !== $controlSum->currency) {
                    $hasCurrencyMismatch = true;
                    continue;
                }

                $actualAmountMinor += $transaction->getAmountMinor();
            }

            if ($hasCurrencyMismatch) {
                ($this->recordNormalizationIssueAction)(new RecordNormalizationIssueCommand(
                    companyId: $companyId,
                    rawRecordId: $rawRecordId,
                    operationGroupId: $controlSum->operationGroupId,
                    kind: NormalizationIssueKind::CURRENCY_MISMATCH,
                    details: [
                        'expectedCurrency' => $controlSum->currency,
                        'operationGroupId' => $controlSum->operationGroupId,
                    ],
                ));
            }

            if ($actualAmountMinor !== $controlSum->amountMinor) {
                ($this->recordNormalizationIssueAction)(new RecordNormalizationIssueCommand(
                    companyId: $companyId,
                    rawRecordId: $rawRecordId,
                    operationGroupId: $controlSum->operationGroupId,
                    kind: NormalizationIssueKind::SUM_MISMATCH,
                    details: [
                        'expectedAmountMinor' => $controlSum->amountMinor,
                        'actualAmountMinor' => $actualAmountMinor,
                        'currency' => $controlSum->currency,
                        'operationGroupId' => $controlSum->operationGroupId,
                    ],
                ));
            }
        }
    }
}
