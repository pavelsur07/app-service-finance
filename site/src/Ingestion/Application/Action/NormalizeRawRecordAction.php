<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\Command\RecordNormalizationIssueCommand;
use App\Ingestion\Application\Command\UpsertFinancialTransactionCommand;
use App\Ingestion\Application\DTO\MappedControlSum;
use App\Ingestion\Application\Service\ListingResolverRegistry;
use App\Ingestion\Application\Service\SystemCounterpartyResolver;
use App\Ingestion\Domain\Contract\RawRecordAwareControlSumMapperInterface;
use App\Ingestion\Domain\Event\AffectedPeriod;
use App\Ingestion\Domain\Event\NormalizationCompletedEvent;
use App\Ingestion\Domain\Service\MapperRegistry;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Exception\RawRecordNotFoundException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class NormalizeRawRecordAction
{
    public function __construct(
        private IngestRawRecordRepository $rawRecordRepository,
        private RawStorageFacade $rawStorageFacade,
        private MapperRegistry $mapperRegistry,
        private SystemCounterpartyResolver $systemCounterpartyResolver,
        private ListingResolverRegistry $listingResolverRegistry,
        private FinancialTransactionRepository $financialTransactionRepository,
        private UpsertFinancialTransactionAction $upsertFinancialTransactionAction,
        private RecordNormalizationIssueAction $recordNormalizationIssueAction,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
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
            $counterpartyId = $this->systemCounterpartyResolver->resolve($rawRecord->getSource());
            if (null === $counterpartyId) {
                $this->logger->warning('System counterparty was not found for ingestion source.', [
                    'companyId' => $command->companyId,
                    'rawRecordId' => $rawRecord->getId(),
                    'source' => $rawRecord->getSource()->value,
                ]);
            }

            $sourceDataRows = [];
            foreach ($mappedTransactions as $index => $mappedTransaction) {
                $sourceDataRows[$index] = $mappedTransaction->sourceData;
            }

            $listingResolutions = $this->listingResolverRegistry->resolveMany(
                $rawRecord->getSource(),
                $command->companyId,
                $sourceDataRows,
            );

            foreach ($mappedTransactions as $index => $mappedTransaction) {
                if (array_key_exists($index, $listingResolutions)) {
                    $listingResolution = $listingResolutions[$index];
                } else {
                    $listingResolution = $this->listingResolverRegistry->resolve(
                        $rawRecord->getSource(),
                        $command->companyId,
                        $mappedTransaction->sourceData,
                    );
                }

                if (null !== $listingResolution?->listingSku && null === $listingResolution->listingId) {
                    $this->logger->warning('Marketplace listing was not found for ingestion transaction.', [
                        'companyId' => $command->companyId,
                        'rawRecordId' => $rawRecord->getId(),
                        'source' => $rawRecord->getSource()->value,
                        'listingSku' => $listingResolution->listingSku,
                    ]);
                }

                $result = ($this->upsertFinancialTransactionAction)(new UpsertFinancialTransactionCommand(
                    companyId: $command->companyId,
                    connectionRef: $rawRecord->getConnectionRef(),
                    shopRef: $rawRecord->getShopRef(),
                    source: $rawRecord->getSource(),
                    mapped: $mappedTransaction,
                    rawRecordId: $rawRecord->getId(),
                    counterpartyId: $counterpartyId,
                    listingId: $listingResolution?->listingId,
                    listingSku: $listingResolution?->listingSku,
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
            // Mark the raw record DONE before recording control-sum issues: issues are
            // diagnostic, so a failure there must not leave the record eligible for a
            // full re-normalization on retry.
            $rawRecord->markNormalizationDone();
            $this->recordControlSumIssues($command->companyId, $rawRecord->getId(), $controlSums);
            $this->entityManager->flush();
            $connection->commit();

            // Only publish when something actually changed. If every upsert returned a
            // no-change result (B3), there is no affected period and nothing for
            // subscribers (P&L dirty-period marking) to do.
            if ([] !== $affectedPeriods) {
                $event = new NormalizationCompletedEvent(
                    companyId: $command->companyId,
                    rawRecordId: $rawRecord->getId(),
                    affectedPeriods: $affectedPeriods,
                );
            }
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent normalization of the same raw record (event + cron safety
            // net) won the race and inserted the same natural key first. The flush
            // here aborts the transaction; roll back and let Messenger retry. On
            // retry the rows already exist, so the upserts become no-change (B3) and
            // this converges without duplicates. No unhandled exception escapes.
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->logger->info('Concurrent normalization detected for raw record; retrying.', [
                'companyId' => $command->companyId,
                'rawRecordId' => $command->rawRecordId,
            ]);

            throw new RecoverableMessageHandlingException('Concurrent normalization detected; retry expected.', previous: $exception);
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
