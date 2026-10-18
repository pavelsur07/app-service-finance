<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Action;

use App\ServiceGateway\Application\DTO\RefreshServiceCoverageResult;
use App\ServiceGateway\Entity\ServiceCoverageRow;
use App\ServiceGateway\Entity\ServiceCoverageSnapshot;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Infrastructure\Client\ServiceCoverageClientInterface;
use App\ServiceGateway\Repository\ServiceCoverageRowRepository;
use App\ServiceGateway\Repository\ServiceCoverageSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Забирает сводку покрытия у сервиса и заменяет его снимок в витрине одной
 * транзакцией. Сбой вызова или контракта бросается до транзакции — витрина
 * остаётся прежней.
 */
final class RefreshServiceCoverageAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceCoverageSnapshotRepository $snapshots,
        private readonly ServiceCoverageRowRepository $rows,
    ) {
    }

    /**
     * @throws ServiceUnavailableException
     * @throws ContractViolationException
     */
    public function __invoke(ServiceCoverageClientInterface $client, \DateTimeImmutable $now): RefreshServiceCoverageResult
    {
        $report = $client->fetchCoverage();
        $service = $client->service();

        $this->entityManager->wrapInTransaction(function () use ($report, $service, $now): void {
            $this->rows->deleteByService($service);

            $snapshot = $this->snapshots->findOneByService($service);
            if (null === $snapshot) {
                $this->snapshots->save(new ServiceCoverageSnapshot($service, $report->generatedAt, $now, $report->periodFrom, $report->periodTo, $report->cabinetsTotal));
            } else {
                $snapshot->replace($report->generatedAt, $now, $report->periodFrom, $report->periodTo, $report->cabinetsTotal);
            }

            foreach ($report->rows as $row) {
                $this->rows->add(new ServiceCoverageRow(
                    service: $service,
                    resourceType: $row->resourceType,
                    periodKey: $row->periodKey,
                    cabinetsExpected: $row->cabinetsExpected,
                    done: $row->byStatus->done,
                    running: $row->byStatus->running,
                    pending: $row->byStatus->pending,
                    notReady: $row->byStatus->notReady,
                    failedRetryable: $row->byStatus->failedRetryable,
                    failedPermanent: $row->byStatus->failedPermanent,
                    lastSuccessAt: $row->lastSuccessAt,
                ));
            }
        });

        return new RefreshServiceCoverageResult($service, \count($report->rows), $report->cabinetsTotal);
    }
}
