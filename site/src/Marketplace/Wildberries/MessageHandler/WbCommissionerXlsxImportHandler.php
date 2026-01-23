<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\MessageHandler;

use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use App\Marketplace\Wildberries\Entity\WildberriesImportLog;
use App\Marketplace\Wildberries\Message\WbCommissionerXlsxImportMessage;
use App\Marketplace\Wildberries\Repository\WildberriesCommissionerXlsxReportRepository;
use App\Marketplace\Wildberries\Service\CommissionerReport\WbCommissionerXlsxFormatValidator;
use App\Marketplace\Wildberries\Service\CommissionerReport\WbCommissionerXlsxImporter;
use App\Service\Storage\StorageService;
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
        private readonly WbCommissionerXlsxImporter $importer,
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

        $result = $this->importer->import($report, $absolutePath);

        $report->setStatus('processed');
        $report->setProcessedAt(new \DateTimeImmutable('now'));
        $report->setRowsTotal($result->rowsTotal);
        $report->setRowsParsed($result->rowsParsed);
        $report->setErrorsCount($result->errorsCount);
        $report->setWarningsCount($result->warningsCount);

        $log->setErrorsCount($result->errorsCount);
        $log->setFinishedAt(new \DateTimeImmutable('now'));
        $log->setMeta([
            'reportId' => $report->getId(),
            'headersHash' => $validation->headersHash,
            'rowsTotal' => $result->rowsTotal,
            'rowsParsed' => $result->rowsParsed,
            'errorsCount' => $result->errorsCount,
            'warningsCount' => $result->warningsCount,
        ]);

        $this->em->persist($log);
        $this->em->flush();
    }
}
