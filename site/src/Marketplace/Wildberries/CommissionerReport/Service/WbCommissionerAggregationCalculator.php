<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Service;

use App\Entity\Company;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbAggregationResultRepository;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbCommissionerReportRowRawRepository;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbCostMappingRepository;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbCostTypeRepository;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbDimensionValueRepository;
use App\Marketplace\Wildberries\CommissionerReport\Service\Dto\WbCommissionerAggregationResult;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbAggregationResult;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbCommissionerReportRowRaw;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbCostMapping;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbCostType;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbDimensionValue;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class WbCommissionerAggregationCalculator
{
    private const STATUS_MAPPED = 'mapped';
    private const STATUS_UNMAPPED = 'unmapped';

    private const DELIVERY_TO_CUSTOMER_COLUMN = 'Услуги по доставке товара покупателю';
    private const COMMISSION_WB_COLUMN = 'Вознаграждение Вайлдберриз (ВВ), без НДС';
    private const COMMISSION_WB_VAT_COLUMN = 'НДС с Вознаграждения Вайлдберриз';
    private const PVZ_COLUMN = 'Возмещение за выдачу и возврат товаров на ПВЗ';
    private const STORAGE_COLUMN = 'Хранение';
    private const REBILL_LOGISTICS_COLUMN = 'Возмещение издержек по перевозке/по складским операциям с товаром';
    private const WITHHOLDING_COLUMN = 'Удержания';
    private const ACQUIRING_COLUMN = 'Эквайринг/Комиссии за организацию платежей';
    private const ACQUIRING_TYPE_COLUMN = 'Тип платежа за Эквайринг/Комиссии за организацию платежей';
    private const LOGISTICS_COLUMN = 'Виды логистики, штрафов и корректировок ВВ';

    private const COST_TYPE_DELIVERY_TO_CUSTOMER = 'DELIVERY_TO_CUSTOMER';
    private const COST_TYPE_COMMISSION_WB = 'COMMISSION_WB';
    private const COST_TYPE_COMMISSION_WB_VAT = 'COMMISSION_WB_VAT';
    private const COST_TYPE_PVZ = 'PVZ';
    private const COST_TYPE_STORAGE = 'STORAGE';
    private const COST_TYPE_REBILL_LOGISTICS = 'REBILL_LOGISTICS';

    private const DIMENSION_KEY_ACQUIRING_TYPE = 'ACQUIRING_TYPE';
    private const DIMENSION_KEY_WITHHOLDING_KIND = 'WITHHOLDING_KIND';

    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly WbCommissionerReportRowRawRepository $rowRawRepository,
        private readonly WbAggregationResultRepository $aggregationResultRepository,
        private readonly WbDimensionValueRepository $dimensionValueRepository,
        private readonly WbCostMappingRepository $costMappingRepository,
        private readonly WbCostTypeRepository $costTypeRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function calculate(
        Company $company,
        WildberriesCommissionerXlsxReport $report,
    ): WbCommissionerAggregationResult {
        $this->aggregationResultRepository->deleteByReport($company, $report);

        $errors = [];

        $costTypeCodes = [
            self::COST_TYPE_DELIVERY_TO_CUSTOMER,
            self::COST_TYPE_COMMISSION_WB,
            self::COST_TYPE_COMMISSION_WB_VAT,
            self::COST_TYPE_PVZ,
            self::COST_TYPE_STORAGE,
            self::COST_TYPE_REBILL_LOGISTICS,
        ];

        $costTypesByCode = $this->loadCostTypesByCode($company, $costTypeCodes);
        $missingCodes = array_diff($costTypeCodes, array_keys($costTypesByCode));
        foreach ($missingCodes as $missingCode) {
            $errors[] = sprintf('WB commissioner aggregation: missing cost type "%s".', $missingCode);
        }

        $dimensionValues = $this->loadDimensionValues($company, $report, [
            self::DIMENSION_KEY_ACQUIRING_TYPE,
            self::DIMENSION_KEY_WITHHOLDING_KIND,
        ]);
        $dimensionMap = $this->buildDimensionValueMap($dimensionValues);

        $costMappings = $this->costMappingRepository->findByDimensionValues($company, $dimensionValues);
        $mappingByDimensionId = $this->buildCostMappingMap($costMappings);

        $rules = [
            [
                'column' => self::DELIVERY_TO_CUSTOMER_COLUMN,
                'costTypeCode' => self::COST_TYPE_DELIVERY_TO_CUSTOMER,
            ],
            [
                'column' => self::COMMISSION_WB_COLUMN,
                'costTypeCode' => self::COST_TYPE_COMMISSION_WB,
            ],
            [
                'column' => self::COMMISSION_WB_VAT_COLUMN,
                'costTypeCode' => self::COST_TYPE_COMMISSION_WB_VAT,
            ],
            [
                'column' => self::PVZ_COLUMN,
                'costTypeCode' => self::COST_TYPE_PVZ,
            ],
            [
                'column' => self::STORAGE_COLUMN,
                'costTypeCode' => self::COST_TYPE_STORAGE,
            ],
            [
                'column' => self::REBILL_LOGISTICS_COLUMN,
                'costTypeCode' => self::COST_TYPE_REBILL_LOGISTICS,
            ],
        ];

        $dimensionRules = [
            [
                'column' => self::ACQUIRING_COLUMN,
                'dimensionKey' => self::DIMENSION_KEY_ACQUIRING_TYPE,
                'dimensionColumn' => self::ACQUIRING_TYPE_COLUMN,
            ],
            [
                'column' => self::WITHHOLDING_COLUMN,
                'dimensionKey' => self::DIMENSION_KEY_WITHHOLDING_KIND,
                'dimensionColumn' => self::LOGISTICS_COLUMN,
            ],
        ];

        $aggregated = [];

        $query = $this->rowRawRepository->createQueryBuilder('row')
            ->andWhere('row.company = :company')
            ->andWhere('row.report = :report')
            ->setParameter('company', $company)
            ->setParameter('report', $report)
            ->getQuery();

        foreach ($query->toIterable() as $row) {
            if (!$row instanceof WbCommissionerReportRowRaw) {
                continue;
            }

            $data = $row->getDataJson();

            foreach ($rules as $rule) {
                $amountMinor = $this->parseAmountToMinor($data[$rule['column']] ?? null);
                if (0 === $amountMinor) {
                    continue;
                }

                $costType = $costTypesByCode[$rule['costTypeCode']] ?? null;
                if (null === $costType) {
                    $errors[] = sprintf(
                        'WB commissioner aggregation: cost type "%s" missing for column "%s".',
                        $rule['costTypeCode'],
                        $rule['column']
                    );
                    continue;
                }

                $this->addAggregatedAmount($aggregated, self::STATUS_MAPPED, $costType, null, $amountMinor);
            }

            foreach ($dimensionRules as $rule) {
                $amountMinor = $this->parseAmountToMinor($data[$rule['column']] ?? null);
                if (0 === $amountMinor) {
                    continue;
                }

                $dimensionValue = $this->resolveDimensionValue(
                    $dimensionMap,
                    $rule['dimensionKey'],
                    $data[$rule['dimensionColumn']] ?? null
                );
                if (null !== $dimensionValue) {
                    $mapping = $mappingByDimensionId[$dimensionValue->getId()] ?? null;
                    if (null !== $mapping) {
                        $this->addAggregatedAmount(
                            $aggregated,
                            self::STATUS_MAPPED,
                            $mapping->getCostType(),
                            $dimensionValue,
                            $amountMinor
                        );
                        continue;
                    }
                }

                $this->addAggregatedAmount(
                    $aggregated,
                    self::STATUS_UNMAPPED,
                    null,
                    $dimensionValue,
                    $amountMinor
                );
            }
        }

        $batchCount = 0;
        foreach ($aggregated as $item) {
            $result = new WbAggregationResult(
                Uuid::uuid4()->toString(),
                $company,
                $report,
                $this->formatAmountFromMinor($item['amountMinor']),
                $item['status'],
                $item['costType'],
                $item['dimensionValue'],
            );
            $this->em->persist($result);
            ++$batchCount;

            if ($batchCount >= self::BATCH_SIZE) {
                $this->em->flush();
                $this->em->clear(WbAggregationResult::class);
                $batchCount = 0;
            }
        }

        if ($batchCount > 0) {
            $this->em->flush();
            $this->em->clear(WbAggregationResult::class);
        }

        return new WbCommissionerAggregationResult(success: [] === $errors, errors: $errors);
    }

    /**
     * @param list<string> $codes
     *
     * @return array<string, WbCostType>
     */
    private function loadCostTypesByCode(Company $company, array $codes): array
    {
        $costTypes = $this->costTypeRepository->createQueryBuilder('costType')
            ->andWhere('costType.company = :company')
            ->andWhere('costType.code IN (:codes)')
            ->setParameter('company', $company)
            ->setParameter('codes', $codes)
            ->getQuery()
            ->getResult();

        $mapped = [];
        foreach ($costTypes as $costType) {
            if (!$costType instanceof WbCostType) {
                continue;
            }
            $mapped[$costType->getCode()] = $costType;
        }

        return $mapped;
    }

    /**
     * @param list<string> $dimensionKeys
     *
     * @return list<WbDimensionValue>
     */
    private function loadDimensionValues(
        Company $company,
        WildberriesCommissionerXlsxReport $report,
        array $dimensionKeys,
    ): array {
        return $this->dimensionValueRepository->createQueryBuilder('dimension')
            ->andWhere('dimension.company = :company')
            ->andWhere('dimension.report = :report')
            ->andWhere('dimension.dimensionKey IN (:dimensionKeys)')
            ->setParameter('company', $company)
            ->setParameter('report', $report)
            ->setParameter('dimensionKeys', $dimensionKeys)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<WbDimensionValue> $dimensionValues
     *
     * @return array<string, array<string, WbDimensionValue>>
     */
    private function buildDimensionValueMap(array $dimensionValues): array
    {
        $map = [];
        foreach ($dimensionValues as $dimensionValue) {
            $dimensionKey = $dimensionValue->getDimensionKey();
            if (!isset($map[$dimensionKey])) {
                $map[$dimensionKey] = [];
            }
            $map[$dimensionKey][$dimensionValue->getNormalizedValue()] = $dimensionValue;
        }

        return $map;
    }

    /**
     * @param list<WbCostMapping> $costMappings
     *
     * @return array<string, WbCostMapping>
     */
    private function buildCostMappingMap(array $costMappings): array
    {
        $map = [];
        foreach ($costMappings as $mapping) {
            $map[$mapping->getDimensionValue()->getId()] = $mapping;
        }

        return $map;
    }

    /**
     * @param array<string, array{amountMinor: int, status: string, costType: ?WbCostType, dimensionValue: ?WbDimensionValue}> $aggregated
     */
    private function addAggregatedAmount(
        array &$aggregated,
        string $status,
        ?WbCostType $costType,
        ?WbDimensionValue $dimensionValue,
        int $amountMinor,
    ): void {
        $key = $status
            .'|'.($costType?->getId() ?? 'null')
            .'|'.($dimensionValue?->getId() ?? 'null');

        if (!isset($aggregated[$key])) {
            $aggregated[$key] = [
                'amountMinor' => 0,
                'status' => $status,
                'costType' => $costType,
                'dimensionValue' => $dimensionValue,
            ];
        }

        $aggregated[$key]['amountMinor'] += $amountMinor;
    }

    private function parseAmountToMinor(mixed $value): int
    {
        if (null === $value) {
            return 0;
        }

        if (is_bool($value)) {
            return $value ? 100 : 0;
        }

        if (is_int($value) || is_float($value)) {
            return (int) round(((float) $value) * 100);
        }

        $stringValue = trim((string) $value);
        if ('' === $stringValue) {
            return 0;
        }

        $normalized = preg_replace('/\s+/', '', $stringValue);
        if (null === $normalized) {
            return 0;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $lastComma = strrpos($normalized, ',');
            $lastDot = strrpos($normalized, '.');
            if (false !== $lastComma && false !== $lastDot && $lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        $normalized = preg_replace('/[^0-9\.\-]/', '', $normalized);
        if (null === $normalized || '' === $normalized || '-' === $normalized) {
            return 0;
        }

        if (!is_numeric($normalized)) {
            return 0;
        }

        return (int) round(((float) $normalized) * 100);
    }

    private function formatAmountFromMinor(int $amountMinor): string
    {
        return number_format($amountMinor / 100, 2, '.', '');
    }

    /**
     * @param array<string, array<string, WbDimensionValue>> $dimensionMap
     */
    private function resolveDimensionValue(
        array $dimensionMap,
        string $dimensionKey,
        mixed $value,
    ): ?WbDimensionValue {
        $normalized = $this->normalizeDimensionValue($value);
        if (null === $normalized) {
            return null;
        }

        return $dimensionMap[$dimensionKey][$normalized] ?? null;
    }

    private function normalizeDimensionValue(mixed $value): ?string
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

        return $normalized ?? $trimmed;
    }
}
