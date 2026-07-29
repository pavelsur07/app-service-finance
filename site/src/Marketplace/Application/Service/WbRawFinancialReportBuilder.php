<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Shared\Domain\ValueObject\Money;

/**
 * Builds a financial control view directly from loaded WB raw rows.
 *
 * This service intentionally has no dependency on the ingestion transaction
 * model. Every amount is parsed through Money and aggregated in minor units.
 */
final class WbRawFinancialReportBuilder
{
    private const CURRENCY = 'RUB';

    /**
     * @var array<string, string>
     */
    private const QUALITY_LABELS = [
        'duplicate_rrd_rows' => 'Повторные rrdId',
        'missing_rrd_rows' => 'Строки без rrdId',
        'missing_report_id_rows' => 'Строки без reportId',
        'invalid_money_values' => 'Некорректные денежные значения',
        'invalid_quantity_rows' => 'Некорректное количество',
        'excluded_product_rows' => 'Исключённые некорректные товарные строки',
        'unclassified_doc_type_rows' => 'Не классифицированный тип документа',
        'negative_commission_rows' => 'Отрицательная расчётная комиссия',
        'unclassified_money_rows' => 'Не классифицированные денежные поля',
        'unsupported_currency_rows' => 'Неподдерживаемая валюта',
        'row_count_mismatches' => 'Расхождения recordsCount с raw',
        'missing_raw_documents' => 'Не найден связанный raw-документ',
        'invalid_raw_documents' => 'Некорректные raw-документы',
        'invalid_raw_rows' => 'Некорректные raw-строки',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const MONEY_FIELDS = [
        'retail_amount' => ['retailAmount', 'retail_amount'],
        'retail_price_with_disc' => ['retailPriceWithDisc', 'retail_price_withdisc_rub'],
        'for_pay' => ['forPay', 'ppvz_for_pay'],
        'acquiring' => ['acquiringFee', 'acquiring_fee'],
        'delivery_service' => ['deliveryService', 'delivery_rub'],
        'delivery_amount' => ['deliveryAmount', 'delivery_amount'],
        'return_amount' => ['returnAmount', 'return_amount'],
        'storage' => ['paidStorage', 'storage_fee'],
        'acceptance' => ['paidAcceptance', 'acceptance'],
        'penalty' => ['penalty'],
        'deduction' => ['deduction'],
        'warehouse_logistics' => ['rebillLogisticCost', 'rebill_logistic_cost'],
        'pvz_processing' => ['ppvzReward', 'ppvz_reward'],
        'additional_payment' => ['additionalPayment', 'additional_payment'],
        'cashback_discount' => ['cashbackDiscount', 'cashback_discount'],
        'loyalty_discount' => ['loyaltyDiscount', 'loyalty_discount'],
        'cashback_amount' => ['cashbackAmount', 'cashback_amount'],
        'cashback_commission_change' => ['cashbackCommissionChange', 'cashback_commission_change'],
    ];

    /**
     * @var array<string, array{label: string, source: string, group: string}>
     */
    private const ARTICLE_DEFINITIONS = [
        'sale_without_spp' => [
            'label' => 'Продажи без СПП',
            'source' => '|retailPriceWithDisc| × |quantity|',
            'group' => 'Товарная часть',
        ],
        'sale_with_spp' => [
            'label' => 'Продажи с СПП',
            'source' => '|retailAmount|',
            'group' => 'Товарная часть',
        ],
        'commission' => [
            'label' => 'Комиссия WB',
            'source' => 'продажа без СПП − |forPay| − |acquiringFee|',
            'group' => 'Товарная часть',
        ],
        'acquiring' => [
            'label' => 'Эквайринг',
            'source' => '|acquiringFee|',
            'group' => 'Товарная часть',
        ],
        'for_pay' => [
            'label' => 'К перечислению за товар',
            'source' => '|forPay|',
            'group' => 'Товарная часть',
        ],
        'logistics_delivery' => [
            'label' => 'Логистика до покупателя',
            'source' => 'deliveryService, deliveryAmount ≠ 0',
            'group' => 'Прочие удержания и начисления',
        ],
        'logistics_return' => [
            'label' => 'Обратная логистика',
            'source' => 'deliveryService, returnAmount ≠ 0',
            'group' => 'Прочие удержания и начисления',
        ],
        'logistics_correction' => [
            'label' => 'Корректировка логистики',
            'source' => 'deliveryService, операция «коррекция»',
            'group' => 'Прочие удержания и начисления',
        ],
        'logistics_other' => [
            'label' => 'Прочая логистика',
            'source' => 'deliveryService',
            'group' => 'Прочие удержания и начисления',
        ],
        'storage' => [
            'label' => 'Хранение',
            'source' => 'paidStorage',
            'group' => 'Прочие удержания и начисления',
        ],
        'acceptance' => [
            'label' => 'Платная приёмка',
            'source' => 'paidAcceptance',
            'group' => 'Прочие удержания и начисления',
        ],
        'penalty' => [
            'label' => 'Штрафы',
            'source' => 'penalty',
            'group' => 'Прочие удержания и начисления',
        ],
        'deduction' => [
            'label' => 'Удержания и выплаты',
            'source' => 'deduction: > 0 удержание, < 0 выплата',
            'group' => 'Прочие удержания и начисления',
        ],
        'warehouse_logistics' => [
            'label' => 'Логистика склада',
            'source' => 'rebillLogisticCost',
            'group' => 'Прочие удержания и начисления',
        ],
        'pvz_processing' => [
            'label' => 'Обработка на ПВЗ',
            'source' => 'ppvzReward для операции ПВЗ',
            'group' => 'Прочие удержания и начисления',
        ],
        'additional_payment' => [
            'label' => 'Доплаты',
            'source' => 'additionalPayment',
            'group' => 'Прочие удержания и начисления',
        ],
        'cashback_discount' => [
            'label' => 'Компенсация скидки лояльности',
            'source' => 'cashbackDiscount',
            'group' => 'Прочие удержания и начисления',
        ],
    ];

    /**
     * @param iterable<array<string, mixed>> $documents
     *
     * @return array<string, mixed>
     */
    public function build(
        iterable $documents,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        ?string $reportId = null,
    ): array {
        $quality = array_fill_keys(array_keys(self::QUALITY_LABELS), 0);
        $articles = $this->emptyArticles();
        $deductions = [];
        $reports = [];
        $operations = [];
        $seenRrdIds = [];
        $rowCount = 0;
        $loadedRowCount = 0;
        $lastSyncAt = null;
        $statusByDate = [];

        foreach ($documents as $document) {
            $businessDate = substr((string) ($document['business_date'] ?? ''), 0, 10);
            if ('' === $businessDate) {
                continue;
            }

            $statusByDate[$businessDate] = [
                'status' => $document['status'] ?? null,
                'records_count' => $document['records_count'] ?? 0,
                'raw_document_id' => $document['raw_document_id'] ?? null,
                'last_error_message' => $document['last_error_message'] ?? null,
                'updated_at' => $document['updated_at'] ?? null,
            ];
            $lastSyncAt = $this->latestTimestamp($lastSyncAt, $document['synced_at'] ?? null);

            if (null === ($document['raw_document_id'] ?? null)) {
                continue;
            }

            if (null === ($document['joined_raw_document_id'] ?? null)) {
                ++$quality['missing_raw_documents'];
                continue;
            }

            $rows = $this->rawRows($document['raw_data'] ?? null);
            if (null === $rows) {
                ++$quality['invalid_raw_documents'];
                continue;
            }

            $loadedRowCount += count($rows);
            if ((int) ($document['records_count'] ?? 0) !== count($rows)) {
                ++$quality['row_count_mismatches'];
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    ++$quality['invalid_raw_rows'];
                    continue;
                }

                $currentReportId = $this->string($row, 'reportId', 'realizationreport_id');
                if (null !== $reportId && $currentReportId !== $reportId) {
                    continue;
                }

                if ('' === $currentReportId) {
                    ++$quality['missing_report_id_rows'];
                }

                $rrdId = $this->string($row, 'rrdId', 'rrd_id');
                if ('' === $rrdId || '0' === $rrdId) {
                    ++$quality['missing_rrd_rows'];
                } elseif (isset($seenRrdIds[$rrdId])) {
                    ++$quality['duplicate_rrd_rows'];
                } else {
                    $seenRrdIds[$rrdId] = true;
                }

                $currency = strtoupper($this->string($row, 'currency'));
                if ('' !== $currency && self::CURRENCY !== $currency) {
                    ++$quality['unsupported_currency_rows'];
                    continue;
                }

                ++$rowCount;
                $invalidMoneyFields = [];
                $amounts = $this->moneyValues($row, $quality, $invalidMoneyFields);
                $docType = $this->normalizeDocType($this->string($row, 'docTypeName', 'doc_type_name'));
                $operationName = $this->string($row, 'sellerOperName', 'supplier_oper_name');
                $quantity = $this->quantity($row, $docType, $operationName, $amounts, $quality);

                if (null === $docType && $this->hasProductMoney($amounts)) {
                    ++$quality['unclassified_doc_type_rows'];
                }

                $rowPayoutMinor = $this->collectProductArticles(
                    $articles,
                    $amounts,
                    $invalidMoneyFields,
                    $docType,
                    $operationName,
                    $quantity,
                    $quality,
                );
                $rowPayoutMinor = $this->sum(
                    $rowPayoutMinor,
                    $this->collectOtherArticles($articles, $amounts, $operationName, $quality),
                );

                $groupReportId = '' === $currentReportId ? 'Без reportId' : $currentReportId;
                if (0 !== $amounts['deduction']) {
                    $this->addDeductionRow(
                        $deductions,
                        $this->string($row, 'bonusTypeName', 'bonus_type_name'),
                        $groupReportId,
                        $businessDate,
                        $amounts['deduction'],
                    );
                }
                $this->addReportRow($reports, $groupReportId, $businessDate, $rowPayoutMinor);
                $this->addOperationRow(
                    $operations,
                    '' === $operationName ? 'Без названия операции' : $operationName,
                    null === $docType ? 'Без типа документа' : $this->docTypeLabel($docType),
                    $groupReportId,
                    $rowPayoutMinor,
                );
            }

            unset($rows);
        }

        $days = $this->coverageDays($dateFrom, $dateTo, $statusByDate);
        $statusCounts = [];
        foreach ($days as $day) {
            $statusCounts[$day['status']] = ($statusCounts[$day['status']] ?? 0) + 1;
        }

        $reportRows = array_values($reports);
        usort($reportRows, static fn (array $left, array $right): int => strnatcasecmp($left['report_id'], $right['report_id']));

        $operationRows = array_values($operations);
        foreach ($operationRows as &$operationRow) {
            unset($operationRow['report_ids']);
        }
        unset($operationRow);
        usort(
            $operationRows,
            static fn (array $left, array $right): int => abs($right['payout_minor']) <=> abs($left['payout_minor']),
        );

        $deductionRows = array_values($deductions);
        foreach ($deductionRows as &$deductionRow) {
            $deductionRow['impact_minor'] = $this->sum(
                $deductionRow['paid_minor'],
                -$deductionRow['withheld_minor'],
            );
            $deductionRow['report_count'] = count(array_filter(
                array_keys($deductionRow['report_ids']),
                static fn (int|string $id): bool => 'Без reportId' !== (string) $id,
            ));
            unset($deductionRow['report_ids']);
        }
        unset($deductionRow);
        // Gross turnover only defines presentation order; exported money totals
        // remain the values accumulated through Money-safe sum().
        usort(
            $deductionRows,
            static fn (array $left, array $right): int => ($right['withheld_minor'] + $right['paid_minor'])
                <=> ($left['withheld_minor'] + $left['paid_minor'])
                ?: abs($right['impact_minor']) <=> abs($left['impact_minor'])
                ?: strnatcasecmp($left['reason'], $right['reason']),
        );

        $articleRows = array_values(array_filter(
            $articles,
            static fn (array $article): bool => 0 !== $article['accrual_minor']
                || 0 !== $article['reversal_minor']
                || in_array($article['key'], ['sale_without_spp', 'sale_with_spp', 'commission', 'acquiring', 'for_pay'], true),
        ));

        $postProductMinor = 0;
        foreach ($articles as $article) {
            if ('Прочие удержания и начисления' === $article['group']) {
                $postProductMinor = $this->sum($postProductMinor, $article['net_minor']);
            }
        }

        $payoutMinor = $this->sum($articles['for_pay']['net_minor'], $postProductMinor);
        $wbCostsMinor = 0;
        foreach ([
            'commission',
            'acquiring',
            'logistics_delivery',
            'logistics_return',
            'logistics_correction',
            'logistics_other',
            'storage',
            'acceptance',
            'penalty',
            'deduction',
            'warehouse_logistics',
            'pvz_processing',
        ] as $costKey) {
            $wbCostsMinor = $this->sum($wbCostsMinor, -$articles[$costKey]['net_minor']);
        }

        return [
            'meta' => [
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'report_id' => $reportId,
                'currency' => self::CURRENCY,
                'last_sync_at' => $lastSyncAt,
            ],
            'coverage' => [
                'expected_days' => count($days),
                'days_with_status' => count($statusByDate),
                'loaded_rows' => $loadedRowCount,
                'status_counts' => $statusCounts,
                'days' => array_reverse($days),
            ],
            'summary' => [
                'row_count' => $rowCount,
                'report_count' => count(array_filter(array_keys($reports), static fn (int|string $id): bool => 'Без reportId' !== (string) $id)),
                'sale_without_spp_minor' => $articles['sale_without_spp']['net_minor'],
                'sale_with_spp_minor' => $articles['sale_with_spp']['net_minor'],
                'wb_costs_minor' => $wbCostsMinor,
                'for_pay_minor' => $articles['for_pay']['net_minor'],
                'post_product_minor' => $postProductMinor,
                'payout_minor' => $payoutMinor,
                'deduction_withheld_minor' => $articles['deduction']['accrual_minor'],
                'deduction_paid_minor' => $articles['deduction']['reversal_minor'],
                'deduction_impact_minor' => $articles['deduction']['net_minor'],
            ],
            'articles' => $articleRows,
            'deductions' => $deductionRows,
            'reports' => $reportRows,
            'operations' => $operationRows,
            'quality_labels' => self::QUALITY_LABELS,
            'quality' => [
                ...$quality,
                'issue_count' => array_sum($quality),
            ],
        ];
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    private function emptyArticles(): array
    {
        $articles = [];
        foreach (self::ARTICLE_DEFINITIONS as $key => $definition) {
            $articles[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'source' => $definition['source'],
                'group' => $definition['group'],
                'accrual_minor' => 0,
                'reversal_minor' => 0,
                'net_minor' => 0,
            ];
        }

        return $articles;
    }

    /**
     * @param array<string, array<string, int|string>> $articles
     * @param array<string, int> $amounts
     * @param array<string, true> $invalidMoneyFields
     * @param array<string, int> $quality
     */
    private function collectProductArticles(
        array &$articles,
        array $amounts,
        array $invalidMoneyFields,
        ?string $docType,
        string $operationName,
        int $quantity,
        array &$quality,
    ): int {
        if (!in_array($docType, ['sale', 'return'], true)) {
            return 0;
        }

        $isReturn = 'return' === $docType;
        $isAdjustment = $this->isSalePayoutAdjustment($docType, $operationName, $amounts);

        if ($isAdjustment) {
            $this->addIncome($articles['for_pay'], $amounts['for_pay']);

            return $amounts['for_pay'];
        }

        if (!$this->hasProductMoney($amounts)) {
            return 0;
        }

        if (
            0 === $quantity
            || 0 === $amounts['retail_price_with_disc']
            || [] !== array_intersect(
                ['retail_amount', 'retail_price_with_disc', 'for_pay', 'acquiring'],
                array_keys($invalidMoneyFields),
            )
        ) {
            ++$quality['excluded_product_rows'];

            return 0;
        }

        try {
            $gross = Money::fromMinor($amounts['retail_price_with_disc'], self::CURRENCY)
                ->abs()
                ->multiply((string) $quantity)
                ->amountMinor();
        } catch (\Throwable) {
            ++$quality['invalid_money_values'];
            ++$quality['excluded_product_rows'];

            return 0;
        }
        $retailAmount = abs($amounts['retail_amount']);
        $forPay = abs($amounts['for_pay']);
        $acquiring = abs($amounts['acquiring']);
        $commission = $this->sum($this->sum($gross, -$forPay), -$acquiring);

        if ($commission < 0) {
            ++$quality['negative_commission_rows'];
        }

        $this->addIncomeByDocumentType($articles['sale_without_spp'], $gross, $isReturn);
        $this->addIncomeByDocumentType($articles['sale_with_spp'], $retailAmount, $isReturn);
        $this->addExpenseByDocumentType($articles['commission'], $commission, $isReturn);
        $this->addExpenseByDocumentType($articles['acquiring'], $acquiring, $isReturn);
        $this->addIncomeByDocumentType($articles['for_pay'], $forPay, $isReturn);

        return $isReturn ? -$forPay : $forPay;
    }

    /**
     * @param array<string, array<string, int|string>> $articles
     * @param array<string, int> $amounts
     * @param array<string, int> $quality
     */
    private function collectOtherArticles(
        array &$articles,
        array $amounts,
        string $operationName,
        array &$quality,
    ): int {
        $payoutMinor = 0;

        if (0 !== $amounts['delivery_service']) {
            $logisticsKey = match (true) {
                0 !== $amounts['delivery_amount'] => 'logistics_delivery',
                0 !== $amounts['return_amount'] => 'logistics_return',
                str_contains(mb_strtolower($operationName), 'коррек') => 'logistics_correction',
                default => 'logistics_other',
            };
            $payoutMinor = $this->sum(
                $payoutMinor,
                $this->addExpense($articles[$logisticsKey], $amounts['delivery_service']),
            );
        }

        foreach (['storage', 'acceptance', 'penalty', 'warehouse_logistics'] as $key) {
            $payoutMinor = $this->sum($payoutMinor, $this->addExpense($articles[$key], $amounts[$key]));
        }
        $payoutMinor = $this->sum(
            $payoutMinor,
            $this->addDeduction($articles['deduction'], $amounts['deduction']),
        );

        if ('возмещение за выдачу и возврат товаров на пвз' === mb_strtolower(trim($operationName))) {
            $payoutMinor = $this->sum(
                $payoutMinor,
                $this->addExpense($articles['pvz_processing'], $amounts['pvz_processing']),
            );
        }

        $payoutMinor = $this->sum(
            $payoutMinor,
            $this->addIncome($articles['additional_payment'], $amounts['additional_payment']),
        );
        $payoutMinor = $this->sum(
            $payoutMinor,
            $this->addIncome($articles['cashback_discount'], $amounts['cashback_discount']),
        );

        if (
            0 !== $amounts['loyalty_discount']
            || 0 !== $amounts['cashback_amount']
            || 0 !== $amounts['cashback_commission_change']
        ) {
            ++$quality['unclassified_money_rows'];
        }

        return $payoutMinor;
    }

    /**
     * @param array<string, int|string> $article
     */
    private function addIncomeByDocumentType(array &$article, int $amountMinor, bool $isReturn): void
    {
        if ($isReturn) {
            $article['reversal_minor'] = $this->sum((int) $article['reversal_minor'], abs($amountMinor));
            $article['net_minor'] = $this->sum((int) $article['net_minor'], -abs($amountMinor));

            return;
        }

        $article['accrual_minor'] = $this->sum((int) $article['accrual_minor'], abs($amountMinor));
        $article['net_minor'] = $this->sum((int) $article['net_minor'], abs($amountMinor));
    }

    /**
     * @param array<string, int|string> $article
     */
    private function addExpenseByDocumentType(array &$article, int $amountMinor, bool $isReturn): void
    {
        $contributionMinor = $isReturn ? $amountMinor : -$amountMinor;
        if ($contributionMinor < 0) {
            $article['accrual_minor'] = $this->sum(
                (int) $article['accrual_minor'],
                abs($contributionMinor),
            );
        } elseif ($contributionMinor > 0) {
            $article['reversal_minor'] = $this->sum(
                (int) $article['reversal_minor'],
                $contributionMinor,
            );
        }

        $article['net_minor'] = $this->sum((int) $article['net_minor'], $contributionMinor);
    }

    /**
     * @param array<string, int|string> $article
     */
    private function addExpense(array &$article, int $rawAmountMinor): int
    {
        if (0 === $rawAmountMinor) {
            return 0;
        }

        $costMinor = abs($rawAmountMinor);
        $article['accrual_minor'] = $this->sum((int) $article['accrual_minor'], $costMinor);
        $article['net_minor'] = $this->sum((int) $article['net_minor'], -$costMinor);

        return -$costMinor;
    }

    /**
     * @param array<string, int|string> $article
     */
    private function addDeduction(array &$article, int $rawAmountMinor): int
    {
        if ($rawAmountMinor > 0) {
            $article['accrual_minor'] = $this->sum((int) $article['accrual_minor'], $rawAmountMinor);
            $article['net_minor'] = $this->sum((int) $article['net_minor'], -$rawAmountMinor);

            return -$rawAmountMinor;
        }

        if ($rawAmountMinor < 0) {
            $paymentMinor = abs($rawAmountMinor);
            $article['reversal_minor'] = $this->sum((int) $article['reversal_minor'], $paymentMinor);
            $article['net_minor'] = $this->sum((int) $article['net_minor'], $paymentMinor);

            return $paymentMinor;
        }

        return 0;
    }

    /**
     * @param array<string, int|string> $article
     */
    private function addIncome(array &$article, int $rawAmountMinor): int
    {
        if ($rawAmountMinor > 0) {
            $article['accrual_minor'] = $this->sum((int) $article['accrual_minor'], $rawAmountMinor);
            $article['net_minor'] = $this->sum((int) $article['net_minor'], $rawAmountMinor);

            return $rawAmountMinor;
        }

        if ($rawAmountMinor < 0) {
            $reversal = abs($rawAmountMinor);
            $article['reversal_minor'] = $this->sum((int) $article['reversal_minor'], $reversal);
            $article['net_minor'] = $this->sum((int) $article['net_minor'], -$reversal);

            return -$reversal;
        }

        return 0;
    }

    /**
     * @param array<string, array<string, int|string>> $reports
     */
    private function addReportRow(array &$reports, string $reportId, string $businessDate, int $payoutMinor): void
    {
        $reports[$reportId] ??= [
            'report_id' => $reportId,
            'date_from' => $businessDate,
            'date_to' => $businessDate,
            'row_count' => 0,
            'payout_minor' => 0,
        ];

        $reports[$reportId]['date_from'] = min($reports[$reportId]['date_from'], $businessDate);
        $reports[$reportId]['date_to'] = max($reports[$reportId]['date_to'], $businessDate);
        ++$reports[$reportId]['row_count'];
        $reports[$reportId]['payout_minor'] = $this->sum($reports[$reportId]['payout_minor'], $payoutMinor);
    }

    /**
     * @param array<string, array<string, mixed>> $operations
     */
    private function addOperationRow(
        array &$operations,
        string $operationName,
        string $docType,
        string $reportId,
        int $payoutMinor,
    ): void {
        $key = $operationName."\0".$docType;
        $operations[$key] ??= [
            'operation_name' => $operationName,
            'doc_type' => $docType,
            'row_count' => 0,
            'report_ids' => [],
            'payout_minor' => 0,
        ];

        ++$operations[$key]['row_count'];
        $operations[$key]['report_ids'][$reportId] = true;
        $operations[$key]['payout_minor'] = $this->sum($operations[$key]['payout_minor'], $payoutMinor);
        $operations[$key]['report_count'] = count($operations[$key]['report_ids']);
    }

    /**
     * @param array<string, array<string, mixed>> $deductions
     */
    private function addDeductionRow(
        array &$deductions,
        string $reason,
        string $reportId,
        string $businessDate,
        int $rawAmountMinor,
    ): void {
        if (0 === $rawAmountMinor) {
            return;
        }

        $reason = '' === $reason ? 'Без расшифровки WB' : $reason;
        $key = 'reason:'.$reason;
        $deductions[$key] ??= [
            'reason' => $reason,
            'date_from' => $businessDate,
            'date_to' => $businessDate,
            'row_count' => 0,
            'report_ids' => [],
            'withheld_minor' => 0,
            'paid_minor' => 0,
        ];

        $amountMinor = abs($rawAmountMinor);
        $deductions[$key]['date_from'] = min($deductions[$key]['date_from'], $businessDate);
        $deductions[$key]['date_to'] = max($deductions[$key]['date_to'], $businessDate);
        ++$deductions[$key]['row_count'];
        $deductions[$key]['report_ids'][$reportId] = true;
        $amountKey = $rawAmountMinor > 0 ? 'withheld_minor' : 'paid_minor';
        $deductions[$key][$amountKey] = $this->sum($deductions[$key][$amountKey], $amountMinor);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $quality
     * @param array<string, true> $invalidFields
     *
     * @return array<string, int>
     */
    private function moneyValues(array $row, array &$quality, array &$invalidFields): array
    {
        $values = [];
        foreach (self::MONEY_FIELDS as $field => $aliases) {
            $rawValue = $this->raw($row, ...$aliases);
            if (null === $rawValue) {
                $values[$field] = 0;
                continue;
            }

            try {
                $minor = Money::fromString($this->decimalString($rawValue), self::CURRENCY)->amountMinor();
                if (\PHP_INT_MIN === $minor) {
                    throw new \InvalidArgumentException('Money value cannot be safely converted to an absolute amount.');
                }
                $values[$field] = $minor;
            } catch (\Throwable) {
                $values[$field] = 0;
                $invalidFields[$field] = true;
                ++$quality['invalid_money_values'];
            }
        }

        return $values;
    }

    /**
     * @param array<string, int> $amounts
     */
    private function hasProductMoney(array $amounts): bool
    {
        foreach (['retail_amount', 'retail_price_with_disc', 'for_pay', 'acquiring'] as $field) {
            if (0 !== $amounts[$field]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $amounts
     * @param array<string, int> $quality
     */
    private function quantity(
        array $row,
        ?string $docType,
        string $operationName,
        array $amounts,
        array &$quality,
    ): int {
        if (!in_array($docType, ['sale', 'return'], true)) {
            return 0;
        }

        $isAdjustment = $this->isSalePayoutAdjustment($docType, $operationName, $amounts);
        if (!$isAdjustment && !$this->hasProductMoney($amounts)) {
            return 0;
        }
        $value = $this->raw($row, 'quantity');
        $normalized = is_scalar($value) ? trim((string) $value) : '';

        if (1 !== preg_match('/^-?[1-9]\d*$/', $normalized)) {
            if (!$isAdjustment) {
                ++$quality['invalid_quantity_rows'];
            }

            return 0;
        }

        $absolute = ltrim($normalized, '-');
        if (\bccomp($absolute, (string) \PHP_INT_MAX, 0) > 0) {
            ++$quality['invalid_quantity_rows'];

            return 0;
        }

        return (int) $absolute;
    }

    /**
     * @param array<string, int> $amounts
     */
    private function isSalePayoutAdjustment(?string $docType, string $operationName, array $amounts): bool
    {
        return 'sale' === $docType
            && in_array(
                mb_strtolower(trim($operationName)),
                ['коррекция продаж', 'добровольная компенсация при возврате'],
                true,
            )
            && 0 === $amounts['retail_amount']
            && 0 === $amounts['acquiring']
            && 0 !== $amounts['for_pay'];
    }

    /**
     * @return list<mixed>|null
     */
    private function rawRows(mixed $rawData): ?array
    {
        if (is_array($rawData)) {
            return array_is_list($rawData) ? $rawData : null;
        }

        if (!is_string($rawData) || '' === trim($rawData)) {
            return null;
        }

        try {
            $decoded = json_decode($rawData, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) && array_is_list($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, array<string, mixed>> $statusByDate
     *
     * @return list<array<string, mixed>>
     */
    private function coverageDays(
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $statusByDate,
    ): array {
        $days = [];
        for ($date = $dateFrom; $date <= $dateTo; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $row = $statusByDate[$key] ?? null;
            $statusValue = null === $row ? 'missing' : (string) ($row['status'] ?? 'missing');
            $status = FinancialReportSyncStatus::tryFrom($statusValue);

            $days[] = [
                'date' => $key,
                'status' => $statusValue,
                'status_label' => $status?->getLabel() ?? ('missing' === $statusValue ? 'Не загружено' : $statusValue),
                'status_tone' => $this->statusTone($statusValue),
                'records_count' => (int) ($row['records_count'] ?? 0),
                'raw_document_id' => $row['raw_document_id'] ?? null,
                'last_error_message' => $row['last_error_message'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        return $days;
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'success' => 'success',
            'empty' => 'secondary',
            'failed', 'failed_final', 'auth_failed', 'conflict' => 'danger',
            'queued', 'loading', 'raw_loaded', 'processing' => 'warning',
            default => 'secondary',
        };
    }

    private function normalizeDocType(string $docType): ?string
    {
        return match (mb_strtolower(trim($docType))) {
            'продажа', 'sale' => 'sale',
            'возврат', 'return' => 'return',
            default => null,
        };
    }

    private function docTypeLabel(string $docType): string
    {
        return match ($docType) {
            'sale' => 'Продажа',
            'return' => 'Возврат',
            default => $docType,
        };
    }

    private function sum(int $leftMinor, int $rightMinor): int
    {
        return Money::fromMinor($leftMinor, self::CURRENCY)
            ->add(Money::fromMinor($rightMinor, self::CURRENCY))
            ->amountMinor();
    }

    private function decimalString(mixed $value): string
    {
        if (is_int($value) || is_string($value)) {
            return (string) $value;
        }

        if (is_float($value) && is_finite($value)) {
            return rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.');
        }

        throw new \InvalidArgumentException('Money value must be scalar decimal.');
    }

    private function latestTimestamp(?string $current, mixed $candidate): ?string
    {
        if ($candidate instanceof \DateTimeInterface) {
            $candidate = $candidate->format(\DateTimeInterface::ATOM);
        }

        if (!is_string($candidate) || '' === trim($candidate)) {
            return $current;
        }

        return null === $current || $candidate > $current ? $candidate : $current;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string ...$keys): string
    {
        $value = $this->raw($row, ...$keys);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function raw(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];
            if (null === $value || (is_string($value) && '' === trim($value))) {
                continue;
            }

            return $value;
        }

        return null;
    }
}
