<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Service;

use App\Cash\Service\Import\File\FileTabularReader;
use App\Entity\Company;
use App\Marketplace\Wildberries\CommissionerReport\Entity\WbCommissionerReportRowRaw;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbCommissionerReportRowRawRepository;
use App\Marketplace\Wildberries\CommissionerReport\Service\Dto\WbCommissionerReportRawIngestResult;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class WbCommissionerReportRawIngestor
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly FileTabularReader $tabularReader,
        private readonly WbCommissionerReportRowRawRepository $rowRawRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function ingest(
        Company $company,
        WildberriesCommissionerXlsxReport $report,
        string $filePath,
    ): WbCommissionerReportRawIngestResult {
        $this->rowRawRepository->deleteByReport($company, $report);

        $reader = $this->tabularReader->openReader($filePath);

        $rowsTotal = 0;
        $rowsParsed = 0;
        $batchCount = 0;

        try {
            $reader->open($filePath);

            foreach ($reader->getSheetIterator() as $sheet) {
                $rowIndex = 0;
                $headerIndex = [];

                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();

                    if (0 === $rowIndex) {
                        foreach ($cells as $index => $value) {
                            $header = $this->normalizeHeader($this->normalizeValue($value, true));
                            if (null === $header) {
                                continue;
                            }
                            if (!isset($headerIndex[$header])) {
                                $headerIndex[$header] = $index;
                            }
                        }
                        ++$rowIndex;
                        continue;
                    }

                    if ($this->isRowEmpty($cells)) {
                        ++$rowIndex;
                        continue;
                    }

                    ++$rowsTotal;

                    $dataJson = [];
                    foreach ($headerIndex as $header => $index) {
                        $dataJson[$header] = $this->normalizeValue($cells[$index] ?? null, false);
                    }

                    $rowRaw = new WbCommissionerReportRowRaw(
                        Uuid::uuid4()->toString(),
                        $company,
                        $report,
                        $rowIndex,
                        $dataJson
                    );

                    $this->em->persist($rowRaw);
                    ++$rowsParsed;
                    ++$batchCount;

                    if ($batchCount >= self::BATCH_SIZE) {
                        $this->em->flush();
                        $this->em->clear(WbCommissionerReportRowRaw::class);
                        $batchCount = 0;
                    }

                    ++$rowIndex;
                }

                break;
            }
        } finally {
            $reader->close();
            if ($batchCount > 0) {
                $this->em->flush();
                $this->em->clear(WbCommissionerReportRowRaw::class);
            }
        }

        return new WbCommissionerReportRawIngestResult(
            rowsTotal: $rowsTotal,
            rowsParsed: $rowsParsed,
            errorsCount: 0,
            warningsCount: 0,
        );
    }

    private function normalizeHeader(?string $header): ?string
    {
        if (null === $header) {
            return null;
        }

        $trimmed = trim($header);
        if ('' === $trimmed) {
            return null;
        }

        return preg_replace('/\s+/', ' ', $trimmed);
    }

    private function normalizeValue(mixed $value, bool $trim): mixed
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $stringValue = $value->format('Y-m-d H:i:s');
        } elseif (is_bool($value)) {
            return $value ? 1 : 0;
        } elseif (is_scalar($value) || $value instanceof \Stringable) {
            $stringValue = (string) $value;
        } else {
            $stringValue = (string) $value;
        }

        if ('' === trim($stringValue)) {
            return null;
        }

        return $trim ? trim($stringValue) : $stringValue;
    }

    private function isRowEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (null === $cell) {
                continue;
            }
            if (is_string($cell) && '' === trim($cell)) {
                continue;
            }
            return false;
        }

        return true;
    }
}
