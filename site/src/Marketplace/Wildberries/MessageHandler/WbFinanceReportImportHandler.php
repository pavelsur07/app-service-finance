<?php

namespace App\Marketplace\Wildberries\MessageHandler;

use App\Marketplace\Wildberries\Message\WbFinanceReportImportMessage;
use App\Marketplace\Wildberries\Service\WildberriesReportDetailImporter;
use App\Repository\CompanyRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class WbFinanceReportImportHandler
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly WildberriesReportDetailImporter $importer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(WbFinanceReportImportMessage $message): void
    {
        $company = $this->companies->find($message->getCompanyId());
        if (null === $company) {
            $this->logger->warning('WB finance import: company not found', [
                'company_id' => $message->getCompanyId(),
                'import_id' => $message->getImportId(),
            ]);

            return;
        }

        $token = trim((string) $company->getWildberriesApiKey());
        if ('' === $token) {
            $this->logger->warning('WB finance import: missing API token', [
                'company_id' => $message->getCompanyId(),
                'import_id' => $message->getImportId(),
            ]);

            return;
        }

        $dateFrom = $this->parseDate($message->getDateFrom());
        $dateTo = $this->parseDate($message->getDateTo());

        if ($dateFrom > $dateTo) {
            $this->logger->warning('WB finance import: invalid date range', [
                'company_id' => $message->getCompanyId(),
                'import_id' => $message->getImportId(),
                'date_from' => $message->getDateFrom(),
                'date_to' => $message->getDateTo(),
            ]);

            return;
        }

        $processed = $this->importer->importWindowWithCursor(
            $company,
            $dateFrom,
            $dateTo,
            $message->getImportId(),
            $message->getRrdId(),
            'daily'
        );

        $this->logger->info('WB finance import completed', [
            'company_id' => (string) $company->getId(),
            'date_from' => $message->getDateFrom(),
            'date_to' => $message->getDateTo(),
            'import_id' => $message->getImportId(),
            'rows_processed' => $processed,
        ]);
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $date) {
            throw new \InvalidArgumentException('Invalid date payload: '.$value);
        }

        return $date;
    }
}
