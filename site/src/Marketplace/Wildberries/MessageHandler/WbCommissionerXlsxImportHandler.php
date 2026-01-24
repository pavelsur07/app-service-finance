<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\MessageHandler;

use App\Marketplace\Wildberries\CommissionerReport\Repository\WbAggregationResultRepository;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbCommissionerReportRowRawRepository;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbDimensionValueRepository;
use App\Marketplace\Wildberries\CommissionerReport\Service\WbCommissionerAggregationCalculator;
use App\Marketplace\Wildberries\CommissionerReport\Service\WbCommissionerDimensionExtractor;
use App\Marketplace\Wildberries\CommissionerReport\Service\WbCommissionerReportRawIngestor;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use App\Marketplace\Wildberries\Entity\WildberriesImportLog;
use App\Marketplace\Wildberries\Message\WbCommissionerXlsxImportMessage;
use App\Marketplace\Wildberries\Repository\WildberriesCommissionerXlsxReportRepository;
use App\Marketplace\Wildberries\Service\CommissionerReport\WbCommissionerXlsxFormatValidator;
use App\Shared\Service\Storage\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class WbCommissionerXlsxImportHandler
{
    public function __construct(
        private readonly WildberriesCommissionerXlsxReportRepository $reports,
        private readonly WbCommissionerXlsxFormatValidator $formatValidator,
        private readonly WbCommissionerReportRawIngestor $rawIngestor,
        private readonly WbCommissionerDimensionExtractor $dimensionExtractor,
        private readonly WbCommissionerAggregationCalculator $aggregationCalculator,
        private readonly WbCommissionerReportRowRawRepository $rowRawRepository,
        private readonly WbDimensionValueRepository $dimensionValueRepository,
        private readonly WbAggregationResultRepository $aggregationResultRepository,
        private readonly StorageService $storageService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(WbCommissionerXlsxImportMessage $message): void
    {
        $report = $this->reports->find($message->getReportId());
        if (!$report instanceof WildberriesCommissionerXlsxReport) {
            $this->logger->warning('WB commissioner report import: report not found', [
                'report_id' => $message->getReportId(),
                'company_id' => $message->getCompanyId(),
            ]);

            return;
        }

        if ((string) $report->getCompany()->getId() !== $message->getCompanyId()) {
            $this->logger->warning('WB commissioner report import: company mismatch', [
                'report_id' => $message->getReportId(),
                'company_id' => $message->getCompanyId(),
            ]);

            return;
        }

        $report->setStatus('processing');
        $this->em->flush();

        $absolutePath = $this->storageService->getAbsolutePath($report->getStoragePath());
        $validation = $this->formatValidator->validate($absolutePath);

        $report->setHeadersHash($validation->headersHash);
        $report->setFormatStatus($validation->status);
        $report->setWarningsJson([] !== $validation->warnings ? $validation->warnings : null);
        $report->setErrorsJson([] !== $validation->errors ? $validation->errors : null);
        $report->setWarningsCount(\count($validation->warnings));
        $report->setErrorsCount(\count($validation->errors));

        $startedAt = new \DateTimeImmutable('now');
        $log = new WildberriesImportLog(Uuid::uuid4()->toString(), $report->getCompany(), $startedAt);
        $log->setSource('wb_commissioner_xlsx');
        $log->setFileName($report->getOriginalFilename());

        if (WbCommissionerXlsxFormatValidator::STATUS_FAILED === $validation->status) {
            $report->setStatus('failed');
            $log->setErrorsCount(\count($validation->errors));
            $log->setFinishedAt(new \DateTimeImmutable('now'));
            $log->setMeta([
                'reportId' => $report->getId(),
                'headersHash' => $validation->headersHash,
                'rowsTotal' => 0,
                'rowsParsed' => 0,
                'errorsCount' => \count($validation->errors),
                'warningsCount' => \count($validation->warnings),
            ]);

            $this->em->persist($log);
            $this->em->flush();

            return;
        }

        $company = $report->getCompany();
        $rowsTotal = 0;
        $rowsParsed = 0;
        $errorsCount = 0;
        $warningsCount = 0;

        try {
            $this->rowRawRepository->deleteByReport($company, $report);
            $this->dimensionValueRepository->deleteByReport($company, $report);
            $this->aggregationResultRepository->deleteByReport($company, $report);

            $rawResult = $this->rawIngestor->ingest($company, $report, $absolutePath);
            $rowsTotal = $rawResult->rowsTotal;
            $rowsParsed = $rawResult->rowsParsed;
            $errorsCount = $rawResult->errorsCount;
            $warningsCount = $rawResult->warningsCount;

            $this->dimensionExtractor->extract($company, $report);

            $aggregationResult = $this->aggregationCalculator->calculate($company, $report);
            if (!$aggregationResult->success) {
                $report->setAggregationStatus('failed');
                $report->setAggregationErrorsJson(
                    [] !== $aggregationResult->errors
                        ? $aggregationResult->errors
                        : ['message' => 'WB commissioner aggregation failed']
                );
                $report->setStatus('failed');
                throw new \RuntimeException('WB commissioner aggregation failed');
            }

            $report->setAggregationStatus('calculated');
            $report->setAggregationErrorsJson(null);
            $report->setStatus('processed');
        } catch (\Throwable $exception) {
            $this->logger->error('WB commissioner report import: pipeline failed', [
                'report_id' => $report->getId(),
                'company_id' => (string) $company->getId(),
                'error' => $exception->getMessage(),
            ]);

            $report->setAggregationStatus('failed');
            if (null === $report->getAggregationErrorsJson()) {
                $report->setAggregationErrorsJson(['message' => $exception->getMessage()]);
            }
            $report->setStatus('failed');
            $errorsCount = max(1, $errorsCount);
        }

        $report->setProcessedAt(new \DateTimeImmutable('now'));
        $report->setRowsTotal($rowsTotal);
        $report->setRowsParsed($rowsParsed);
        $report->setErrorsCount($errorsCount);
        $report->setWarningsCount($warningsCount);

        $log->setErrorsCount($errorsCount);
        $log->setFinishedAt(new \DateTimeImmutable('now'));
        $log->setMeta([
            'reportId' => $report->getId(),
            'headersHash' => $validation->headersHash,
            'rowsTotal' => $rowsTotal,
            'rowsParsed' => $rowsParsed,
            'errorsCount' => $errorsCount,
            'warningsCount' => $warningsCount,
        ]);

        $this->em->persist($log);
        $this->em->flush();
    }
}
