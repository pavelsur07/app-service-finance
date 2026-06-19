<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\RecordNormalizationIssueCommand;
use App\Ingestion\Entity\NormalizationIssue;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class RecordNormalizationIssueAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordNormalizationIssueCommand $command): void
    {
        $issue = new NormalizationIssue(
            companyId: $command->companyId,
            rawRecordId: $command->rawRecordId,
            operationGroupId: $command->operationGroupId,
            kind: $command->kind,
            details: $command->details,
        );

        $this->entityManager->persist($issue);

        $this->logger->warning('Ingestion normalization issue recorded.', [
            'companyId' => $command->companyId,
            'rawRecordId' => $command->rawRecordId,
            'operationGroupId' => $command->operationGroupId,
            'kind' => $command->kind->value,
        ]);
    }
}
