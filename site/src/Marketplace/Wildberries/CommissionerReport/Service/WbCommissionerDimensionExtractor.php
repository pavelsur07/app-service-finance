<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Service;

use App\Entity\Company;
use App\Marketplace\Wildberries\CommissionerReport\Entity\WbCommissionerReportRowRaw;
use App\Marketplace\Wildberries\CommissionerReport\Entity\WbDimensionValue;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbCommissionerReportRowRawRepository;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbDimensionValueRepository;
use App\Marketplace\Wildberries\CommissionerReport\Service\Dto\WbCommissionerDimensionExtractResult;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class WbCommissionerDimensionExtractor
{
    private const LOGISTICS_KIND = 'LOGISTICS_KIND';
    private const ACQUIRING_TYPE = 'ACQUIRING_TYPE';
    private const WITHHOLDING_KIND = 'WITHHOLDING_KIND';

    private const LOGISTICS_COLUMN = 'Виды логистики, штрафов и корректировок ВВ';
    private const ACQUIRING_COLUMN = 'Тип платежа за Эквайринг/Комиссии за организацию платежей';
    private const WITHHOLDING_COLUMN = 'Удержания';

    public function __construct(
        private readonly WbCommissionerReportRowRawRepository $rowRawRepository,
        private readonly WbDimensionValueRepository $dimensionValueRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function extract(
        Company $company,
        WildberriesCommissionerXlsxReport $report,
    ): WbCommissionerDimensionExtractResult {
        $this->dimensionValueRepository->deleteByReport($company, $report);

        $query = $this->rowRawRepository->createQueryBuilder('row')
            ->andWhere('row.company = :company')
            ->andWhere('row.report = :report')
            ->setParameter('company', $company)
            ->setParameter('report', $report)
            ->getQuery();

        $dimensions = [];

        foreach ($query->toIterable() as $row) {
            if (!$row instanceof WbCommissionerReportRowRaw) {
                continue;
            }

            $data = $row->getDataJson();

            $this->collectDimension(
                $dimensions,
                self::LOGISTICS_KIND,
                $data[self::LOGISTICS_COLUMN] ?? null
            );

            $this->collectDimension(
                $dimensions,
                self::ACQUIRING_TYPE,
                $data[self::ACQUIRING_COLUMN] ?? null
            );

            if ($this->isNonZeroAmount($data[self::WITHHOLDING_COLUMN] ?? null)) {
                $this->collectDimension(
                    $dimensions,
                    self::WITHHOLDING_KIND,
                    $data[self::LOGISTICS_COLUMN] ?? null
                );
            }
        }

        $dimensionsTotal = 0;
        foreach ($dimensions as $item) {
            $dimension = new WbDimensionValue(
                Uuid::uuid4()->toString(),
                $company,
                $report,
                $item['dimensionKey'],
                $item['value'],
                $item['normalizedValue']
            );
            $dimension->setOccurrences($item['occurrences']);
            $this->em->persist($dimension);
            ++$dimensionsTotal;
        }

        if ($dimensionsTotal > 0) {
            $this->em->flush();
        }

        return new WbCommissionerDimensionExtractResult(dimensionsTotal: $dimensionsTotal);
    }

    /**
     * @param array<string, array{dimensionKey: string, value: string, normalizedValue: string, occurrences: int}> $dimensions
     */
    private function collectDimension(array &$dimensions, string $dimensionKey, mixed $value): void
    {
        $normalized = $this->normalizeDimensionValue($value);
        if (null === $normalized) {
            return;
        }

        $key = $dimensionKey.'|'.$normalized['normalizedValue'];
        if (!isset($dimensions[$key])) {
            $dimensions[$key] = [
                'dimensionKey' => $dimensionKey,
                'value' => $normalized['value'],
                'normalizedValue' => $normalized['normalizedValue'],
                'occurrences' => 1,
            ];
            return;
        }

        ++$dimensions[$key]['occurrences'];
    }

    /**
     * @return array{value: string, normalizedValue: string}|null
     */
    private function normalizeDimensionValue(mixed $value): ?array
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

        $trimmed = trim($stringValue);
        if ('' === $trimmed) {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $trimmed);

        return [
            'value' => $trimmed,
            'normalizedValue' => $normalized ?? $trimmed,
        ];
    }

    private function isNonZeroAmount(mixed $value): bool
    {
        if (null === $value) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return 0.0 !== (float) $value;
        }

        $stringValue = trim((string) $value);
        if ('' === $stringValue) {
            return false;
        }

        $normalized = str_replace(["\u{00A0}", ' '], '', $stringValue);
        $normalized = str_replace(',', '.', $normalized);

        if (is_numeric($normalized)) {
            return 0.0 !== (float) $normalized;
        }

        return true;
    }
}
