<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Service\CommissionerReport;

use App\Cash\Service\Import\File\FileTabularReader;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use App\Marketplace\Wildberries\Entity\WildberriesReportDetail;
use App\Marketplace\Wildberries\Service\CommissionerReport\Dto\ImportResultDTO;
use App\Service\Storage\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class WbCommissionerXlsxImporter
{
    private const BATCH_SIZE = 200;
    private const ERRORS_LIMIT = 20;

    public function __construct(
        private readonly FileTabularReader $tabularReader,
        private readonly StorageService $storageService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function import(WildberriesCommissionerXlsxReport $report): ImportResultDTO
    {
        $absoluteFilePath = $this->storageService->getAbsolutePath($report->getStoragePath());
        $company = $report->getCompany();
        $importId = $report->getId();

        $this->em->createQueryBuilder()
            ->delete(WildberriesReportDetail::class, 'detail')
            ->where('detail.importId = :importId')
            ->setParameter('importId', $importId)
            ->getQuery()
            ->execute();

        $reader = $this->tabularReader->openReader($absoluteFilePath);

        $rowsTotal = 0;
        $rowsParsed = 0;
        $errors = [];
        $errorsCount = 0;
        $batchCount = 0;
        $now = new \DateTimeImmutable('now');

        try {
            $reader->open($absoluteFilePath);

            foreach ($reader->getSheetIterator() as $sheet) {
                $rowIndex = 0;
                $headerIndex = [];
                $headerLabels = [];

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
                            $headerLabels[$index] = $header;
                        }
                        ++$rowIndex;
                        continue;
                    }

                    if ($this->isRowEmpty($cells)) {
                        ++$rowIndex;
                        continue;
                    }

                    ++$rowsTotal;

                    $rowByHeader = [];
                    foreach ($headerIndex as $label => $index) {
                        $rowByHeader[$label] = $this->normalizeValue($cells[$index] ?? null, false);
                    }

                    $supplierOperName = $this->normalizeString($rowByHeader['Обоснование для оплаты'] ?? null);
                    $docTypeName = $this->resolveDocTypeName($supplierOperName, $rowByHeader);

                    $saleDt = $this->parseDate(
                        $this->getCellValue($cells, $headerIndex, 'Дата продажи'),
                        'Дата продажи',
                        $rowIndex,
                        $errors,
                        $errorsCount
                    );
                    $orderDt = $this->parseDate(
                        $this->getCellValue($cells, $headerIndex, 'Дата заказа покупателем'),
                        'Дата заказа покупателем',
                        $rowIndex,
                        $errors,
                        $errorsCount
                    );
                    $rrDt = $saleDt ?? $orderDt;

                    if (null === $rrDt) {
                        $this->addError($errors, $errorsCount, sprintf('Строка %d: нет даты продажи/заказа.', $rowIndex));
                        ++$rowIndex;
                        continue;
                    }

                    $acquiringFee = $this->parseDecimal(
                        $rowByHeader['Эквайринг/Комиссии за организацию платежей'] ?? null,
                        'Эквайринг/Комиссии за организацию платежей',
                        $rowIndex,
                        $errors,
                        $errorsCount
                    );
                    $deliveryRub = $this->parseDecimal(
                        $rowByHeader['Услуги по доставке товара покупателю'] ?? null,
                        'Услуги по доставке товара покупателю',
                        $rowIndex,
                        $errors,
                        $errorsCount
                    );
                    $storageFee = $this->parseDecimal(
                        $rowByHeader['Хранение'] ?? null,
                        'Хранение',
                        $rowIndex,
                        $errors,
                        $errorsCount
                    );
                    $rebillLogisticCost = $this->parseDecimal(
                        $rowByHeader['Возмещение издержек по перевозке/по складским операциям с товаром'] ?? null,
                        'Возмещение издержек по перевозке/по складским операциям с товаром',
                        $rowIndex,
                        $errors,
                        $errorsCount
                    );
                    $deduction = $this->parseDecimal(
                        $rowByHeader['Удержания'] ?? null,
                        'Удержания',
                        $rowIndex,
                        $errors,
                        $errorsCount
                    );
                    $ppvzSalesCommission = $this->parseDecimal(
                        $rowByHeader['Вознаграждение Вайлдберриз (ВВ), без НДС'] ?? null,
                        'Вознаграждение Вайлдберриз (ВВ), без НДС',
                        $rowIndex,
                        $errors,
                        $errorsCount
                    );
                    $ppvzSalesCommissionVat = $this->parseDecimal(
                        $rowByHeader['НДС с Вознаграждения Вайлдберриз'] ?? null,
                        'НДС с Вознаграждения Вайлдберриз',
                        $rowIndex,
                        $errors,
                        $errorsCount
                    );
                    $ppvzForPay = $this->parseDecimal(
                        $rowByHeader['Возмещение за выдачу и возврат товаров на ПВЗ'] ?? null,
                        'Возмещение за выдачу и возврат товаров на ПВЗ',
                        $rowIndex,
                        $errors,
                        $errorsCount
                    );

                    $raw = [
                        'row' => $rowByHeader,
                        'deduction' => $deduction,
                        'rebill_logistic_cost' => $rebillLogisticCost,
                        'ppvz_sales_commission' => $ppvzSalesCommission,
                        'ppvz_sales_commission_vat' => $ppvzSalesCommissionVat,
                        'ppvz_for_pay' => $ppvzForPay,
                        'ppvz_reward' => $ppvzSalesCommission,
                    ];

                    $detail = (new WildberriesReportDetail())
                        ->setId(Uuid::uuid4()->toString())
                        ->setCompany($company)
                        ->setImportId($importId)
                        ->setRrdId($this->generateRrdId($report->getFileHash(), $rowIndex))
                        ->setSaleDt($saleDt)
                        ->setOrderDt($orderDt)
                        ->setRrDt($rrDt)
                        ->setSupplierOperName($supplierOperName)
                        ->setDocTypeName($docTypeName)
                        ->setAcquiringFee($acquiringFee)
                        ->setDeliveryRub($deliveryRub)
                        ->setStorageFee($storageFee)
                        ->setPpvzSalesCommission($ppvzSalesCommission)
                        ->setPpvzForPay($ppvzForPay)
                        ->setStatusUpdatedAt($rrDt ?? $now)
                        ->setCreatedAt($now)
                        ->setUpdatedAt($now)
                        ->setRaw($raw);

                    $this->em->persist($detail);
                    ++$rowsParsed;
                    ++$batchCount;

                    if ($batchCount >= self::BATCH_SIZE) {
                        $this->em->flush();
                        $this->em->clear(WildberriesReportDetail::class);
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
                $this->em->clear(WildberriesReportDetail::class);
            }
        }

        $report->setErrorsJson([] !== $errors ? $errors : null);

        return new ImportResultDTO(
            rowsTotal: $rowsTotal,
            rowsParsed: $rowsParsed,
            errorsCount: $errorsCount,
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

    private function normalizeValue(mixed $value, bool $trim): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $stringValue = $value->format('Y-m-d H:i:s');
        } elseif (is_bool($value)) {
            $stringValue = $value ? '1' : '0';
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

    private function normalizeString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    private function resolveDocTypeName(?string $supplierOperName, array $rowByHeader): ?string
    {
        $docType = null;

        if ('Логистика' === $supplierOperName) {
            $docType = $rowByHeader['Виды логистики, штрафов и корректировок ВВ'] ?? null;
        } elseif ('Эквайринг' === $supplierOperName) {
            $docType = $rowByHeader['Тип платежа за Эквайринг/Комиссии за организацию платежей'] ?? null;
        } elseif ('Удержания' === $supplierOperName) {
            $docType = $rowByHeader['Виды логистики, штрафов и корректировок ВВ'] ?? null;
        } else {
            $docType = $rowByHeader['Тип документа'] ?? null;
        }

        $docType = $this->normalizeString($docType);
        if (null === $docType) {
            $docType = $this->normalizeString($rowByHeader['Тип документа'] ?? null);
        }

        return $docType;
    }

    private function parseDate(
        mixed $value,
        string $label,
        int $rowIndex,
        array &$errors,
        int &$errorsCount,
    ): ?\DateTimeImmutable {
        if (null === $value || '' === $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_numeric($value)) {
            $excelDate = (float) $value;
            $base = new \DateTimeImmutable('1899-12-30 00:00:00');
            $days = (int) floor($excelDate);
            $seconds = (int) round(($excelDate - $days) * 86400);

            return $base->modify(sprintf('+%d days +%d seconds', $days, $seconds));
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            $this->addError($errors, $errorsCount, sprintf('Строка %d: не удалось распознать дату "%s" (%s).', $rowIndex, (string) $value, $label));
        }

        return null;
    }

    private function parseDecimal(
        mixed $value,
        string $label,
        int $rowIndex,
        array &$errors,
        int &$errorsCount,
    ): ?string {
        if (null === $value) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ('' === $value) {
                return null;
            }
        }

        if (is_bool($value)) {
            $this->addError($errors, $errorsCount, sprintf('Строка %d: неверный формат числа (%s).', $rowIndex, $label));
            return null;
        }

        if (is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        $normalized = str_replace(["\u{00A0}", ' '], '', (string) $value);
        $normalized = str_replace(',', '.', $normalized);

        if (!is_numeric($normalized)) {
            $this->addError($errors, $errorsCount, sprintf('Строка %d: не удалось распознать число "%s" (%s).', $rowIndex, (string) $value, $label));
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function addError(array &$errors, int &$errorsCount, string $message): void
    {
        ++$errorsCount;
        if (\count($errors) < self::ERRORS_LIMIT) {
            $errors[] = $message;
        }
    }

    private function getCellValue(array $cells, array $headerIndex, string $label): mixed
    {
        if (!isset($headerIndex[$label])) {
            return null;
        }

        $index = $headerIndex[$label];

        return $cells[$index] ?? null;
    }

    private function generateRrdId(string $fileHash, int $rowIndex): int
    {
        $hash = sha1($fileHash.':'.$rowIndex);
        $hex = substr($hash, 0, 15);
        $rrdId = (int) base_convert($hex, 16, 10);

        if ($rrdId < 0 || $rrdId > PHP_INT_MAX) {
            throw new \RuntimeException('Generated rrdId is out of bigint range.');
        }

        return $rrdId;
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
