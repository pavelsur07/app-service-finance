<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Application\Service\WbRawFinancialReportBuilder;
use App\Marketplace\Application\Service\WbRawFinancialReportProductEnricher;
use App\Marketplace\Infrastructure\Query\WbRawFinancialReportQuery;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/wb-finance-report')]
#[IsGranted('ROLE_USER')]
final class WbRawFinancialReportController extends AbstractController
{
    private const MAX_PERIOD_DAYS = 93;
    private const BUSINESS_TIMEZONE = 'Europe/Moscow';
    private const MAPPING_STATUS_LABELS = [
        'mapped' => 'Сопоставлен',
        'conflict' => 'Конфликт идентификаторов',
        'unmapped' => 'Не сопоставлен',
    ];
    private const COST_STATUS_LABELS = [
        'complete' => 'Полное',
        'partial' => 'Частичное',
        'missing' => 'Нет цены',
    ];

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly WbRawFinancialReportQuery $reportQuery,
        private readonly WbRawFinancialReportBuilder $reportBuilder,
        private readonly WbRawFinancialReportProductEnricher $productEnricher,
    ) {
    }

    #[Route('', name: 'marketplace_wb_finance_report', methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            [$dateFrom, $dateTo, $reportId] = $this->resolveFilters($request);
        } catch (\InvalidArgumentException $exception) {
            return $this->render('marketplace/wb_finance_report.html.twig', [
                'active_tab' => 'wb_finance_report',
                'report' => null,
                'filter_error' => $exception->getMessage(),
                'filters' => $this->rawFilters($request),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $report = $this->productEnricher->enrich($companyId, $this->reportBuilder->build(
            $this->reportQuery->findByCompanyAndPeriod(
                $companyId,
                $dateFrom,
                $dateTo,
            ),
            $dateFrom,
            $dateTo,
            $reportId,
        ));

        return $this->render('marketplace/wb_finance_report.html.twig', [
            'active_tab' => 'wb_finance_report',
            'report' => $report,
            'product_status_labels' => [
                'mapping' => self::MAPPING_STATUS_LABELS,
                'cost' => self::COST_STATUS_LABELS,
            ],
            'filter_error' => null,
            'filters' => [
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'report_id' => $reportId ?? '',
            ],
        ]);
    }

    #[Route('/csv', name: 'marketplace_wb_finance_report_csv', methods: ['GET'])]
    public function csv(Request $request): Response
    {
        try {
            [$dateFrom, $dateTo, $reportId] = $this->resolveFilters($request);
        } catch (\InvalidArgumentException $exception) {
            return new Response($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $report = $this->productEnricher->enrich($companyId, $this->reportBuilder->build(
            $this->reportQuery->findByCompanyAndPeriod(
                $companyId,
                $dateFrom,
                $dateTo,
            ),
            $dateFrom,
            $dateTo,
            $reportId,
        ));

        $response = new Response($this->buildCsv($report), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                sprintf('wb-finance-raw-%s-%s.csv', $dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d')),
            ),
        );

        return $response;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string|null}
     */
    private function resolveFilters(Request $request): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone(self::BUSINESS_TIMEZONE));
        $defaultTo = $today->modify('-1 day');
        $defaultFrom = $defaultTo->modify('first day of this month');
        $dateFrom = $this->date((string) $request->query->get('date_from', $defaultFrom->format('Y-m-d')), 'Дата с');
        $dateTo = $this->date((string) $request->query->get('date_to', $defaultTo->format('Y-m-d')), 'Дата по');

        if ($dateFrom > $dateTo) {
            throw new \InvalidArgumentException('Дата с должна быть не позже даты по.');
        }

        if ((int) $dateFrom->diff($dateTo)->format('%a') >= self::MAX_PERIOD_DAYS) {
            throw new \InvalidArgumentException(sprintf('Период отчёта не может превышать %d дня.', self::MAX_PERIOD_DAYS));
        }

        $reportId = trim((string) $request->query->get('report_id', ''));
        if ('' !== $reportId && 1 !== preg_match('/^[1-9]\d{0,31}$/', $reportId)) {
            throw new \InvalidArgumentException('reportId должен быть положительным числом длиной до 32 цифр.');
        }

        return [$dateFrom, $dateTo, '' === $reportId ? null : $reportId];
    }

    private function date(string $value, string $label): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone(self::BUSINESS_TIMEZONE);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $timezone);
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            false === $date
            || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))
        ) {
            throw new \InvalidArgumentException(sprintf('%s должна быть в формате ГГГГ-ММ-ДД.', $label));
        }

        return $date;
    }

    /**
     * @return array{date_from: string, date_to: string, report_id: string}
     */
    private function rawFilters(Request $request): array
    {
        return [
            'date_from' => (string) $request->query->get('date_from', ''),
            'date_to' => (string) $request->query->get('date_to', ''),
            'report_id' => (string) $request->query->get('report_id', ''),
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    private function buildCsv(array $report): string
    {
        $stream = fopen('php://temp', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('Не удалось подготовить CSV отчёт.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        $this->writeCsvRow($stream, ['Показатель', 'Значение']);
        $this->writeCsvRow($stream, ['Период', $report['meta']['date_from'].' — '.$report['meta']['date_to']]);
        $this->writeCsvRow($stream, [
            'reportId',
            $this->spreadsheetSafe((string) ($report['meta']['report_id'] ?? 'Все')),
        ]);
        $this->writeCsvRow($stream, ['Строк raw', (string) $report['summary']['row_count']]);
        $this->writeCsvRow($stream, ['Продажи без СПП', $this->decimal($report['summary']['sale_without_spp_minor'])]);
        $this->writeCsvRow($stream, ['Продажи с СПП', $this->decimal($report['summary']['sale_with_spp_minor'])]);
        $this->writeCsvRow($stream, ['Расходы WB (нетто)', $this->decimal($report['summary']['wb_costs_minor'])]);
        $this->writeCsvRow($stream, ['Расчётное перечисление', $this->decimal($report['summary']['payout_minor'])]);

        $this->writeCsvRow($stream, []);
        $this->writeCsvRow($stream, ['Статья', 'Источник / формула', 'Начислено', 'Возврат / сторно', 'Влияние на перечисление']);
        foreach ($report['articles'] as $article) {
            $this->writeCsvRow($stream, [
                $article['label'],
                $article['source'],
                $this->decimal($article['accrual_minor']),
                $this->decimal($article['reversal_minor']),
                $this->decimal($article['net_minor']),
            ]);
        }

        if ([] !== $report['deductions']) {
            $this->writeCsvRow($stream, []);
            $this->writeCsvRow($stream, [
                'Основание операции WB',
                'Дата с',
                'Дата по',
                'Строк',
                'reportId с номером, шт.',
                'Удержано',
                'Выплачено WB',
                'Влияние на перечисление',
            ]);
            foreach ($report['deductions'] as $row) {
                $this->writeCsvRow($stream, [
                    $this->spreadsheetSafe((string) $row['reason']),
                    $row['date_from'],
                    $row['date_to'],
                    (string) $row['row_count'],
                    (string) $row['report_count'],
                    $this->decimal($row['withheld_minor']),
                    $this->decimal($row['paid_minor']),
                    $this->decimal($row['impact_minor']),
                ]);
            }
            $this->writeCsvRow($stream, [
                'Итого',
                '',
                '',
                '',
                '',
                $this->decimal($report['summary']['deduction_withheld_minor']),
                $this->decimal($report['summary']['deduction_paid_minor']),
                $this->decimal($report['summary']['deduction_impact_minor']),
            ]);
        }

        $productSummary = $report['product_summary'];
        $this->writeCsvRow($stream, []);
        $this->writeCsvRow($stream, ['Товары и себестоимость']);
        $this->writeCsvRow($stream, ['Показатель по товарам', 'Значение']);
        $this->writeCsvRow($stream, ['Варианты SKU', (string) $productSummary['sku_count']]);
        $this->writeCsvRow($stream, ['Продано, шт.', (string) $productSummary['sold_quantity']]);
        $this->writeCsvRow($stream, ['Возвращено, шт.', (string) $productSummary['returned_quantity']]);
        $this->writeCsvRow($stream, ['Нетто, шт.', (string) $productSummary['net_quantity']]);
        $this->writeCsvRow($stream, [
            'Продажи без СПП (нетто)',
            $this->decimal($productSummary['net_sales_without_spp_minor']),
        ]);
        $this->writeCsvRow($stream, [
            'Продажи с СПП (нетто)',
            $this->decimal($productSummary['net_sales_with_spp_minor']),
        ]);
        $this->writeCsvRow($stream, [
            'К перечислению за товар (по SKU)',
            $this->decimal($productSummary['for_pay_minor']),
        ]);
        $this->writeCsvRow($stream, [
            'Себестоимость продаж',
            $this->decimal($productSummary['sold_cost_minor']),
        ]);
        $this->writeCsvRow($stream, [
            'Себестоимость возвратов',
            $this->decimal($productSummary['returned_cost_minor']),
        ]);
        $this->writeCsvRow($stream, [
            'Нетто-себестоимость',
            $this->decimal($productSummary['net_cost_minor']),
        ]);
        $this->writeCsvRow($stream, [
            'Покрытие себестоимостью, %',
            null === $productSummary['cost_coverage_percent']
                ? ''
                : (string) $productSummary['cost_coverage_percent'],
        ]);
        $this->writeCsvRow($stream, [
            'Без себестоимости, шт.',
            (string) $productSummary['missing_cost_quantity'],
        ]);
        $this->writeCsvRow($stream, [
            'Самая ранняя цена (fallback), шт.',
            (string) $productSummary['fallback_cost_quantity'],
        ]);
        $this->writeCsvRow($stream, [
            'Сопоставлено SKU',
            (string) $productSummary['mapped_sku_count'],
        ]);
        $this->writeCsvRow($stream, [
            'Не сопоставлено SKU',
            (string) $productSummary['unmapped_sku_count'],
        ]);
        $this->writeCsvRow($stream, [
            'Конфликтов идентификаторов SKU',
            (string) $productSummary['conflict_sku_count'],
        ]);
        $this->writeCsvRow($stream, [
            'Результат до общих расходов WB',
            null === $productSummary['result_minor'] ? '' : $this->decimal($productSummary['result_minor']),
        ]);
        $this->writeCsvRow($stream, [
            'SKU с рассчитанным результатом',
            (string) $productSummary['complete_cost_sku_count'],
        ]);
        $this->writeCsvRow($stream, [
            'Рентабельность к продажам без СПП, %',
            null === $productSummary['profitability_percent']
                ? ''
                : (string) $productSummary['profitability_percent'],
        ]);
        $this->writeCsvRow($stream, [
            'Рассчитанная часть результата',
            $this->decimal($productSummary['known_result_minor']),
        ]);
        $this->writeCsvRow($stream, [
            'Не распределено по SKU',
            $this->decimal($productSummary['unallocated_for_pay_minor']),
        ]);
        $this->writeCsvRow($stream, [
            'Сверка продаж',
            $this->decimal($productSummary['sales_reconciliation_minor']),
        ]);

        $this->writeCsvRow($stream, []);
        $this->writeCsvRow($stream, [
            'Наименование',
            'Артикул продавца',
            'nmId',
            'Размер',
            'Barcode',
            'Сопоставление',
            'Продано, шт.',
            'Возвращено, шт.',
            'Нетто, шт.',
            'Продано без СПП',
            'Возвраты без СПП',
            'Нетто без СПП',
            'Продано с СПП',
            'Возвраты с СПП',
            'Нетто с СПП',
            'К перечислению за продажи',
            'К перечислению за возвраты',
            'К перечислению за товар (нетто)',
            'Себестоимость продаж',
            'Себестоимость возвратов',
            'Нетто-себестоимость',
            'Покрыто себестоимостью, шт.',
            'Без себестоимости, шт.',
            'Самая ранняя цена (fallback), шт.',
            'Покрытие, %',
            'Статус себестоимости',
            'Результат до общих расходов WB',
            'Рентабельность к продажам без СПП, %',
            'Возвраты / продажи периода, %',
        ]);
        foreach ($report['products'] as $product) {
            $this->writeCsvRow($stream, [
                $this->spreadsheetSafe((string) $product['name']),
                $this->spreadsheetSafe((string) $product['supplier_sku']),
                $this->spreadsheetSafe((string) $product['nm_id']),
                $this->spreadsheetSafe('UNKNOWN' === $product['size'] ? '' : (string) $product['size']),
                $this->spreadsheetSafe(implode(', ', $product['barcodes'])),
                $this->mappingStatusLabel((string) $product['mapping_status']),
                (string) $product['sold_quantity'],
                (string) $product['returned_quantity'],
                (string) $product['net_quantity'],
                $this->decimal($product['sales_without_spp_minor']),
                $this->decimal($product['returns_without_spp_minor']),
                $this->decimal($product['net_sales_without_spp_minor']),
                $this->decimal($product['sales_with_spp_minor']),
                $this->decimal($product['returns_with_spp_minor']),
                $this->decimal($product['net_sales_with_spp_minor']),
                $this->decimal($product['sales_for_pay_minor']),
                $this->decimal($product['returns_for_pay_minor']),
                $this->decimal($product['for_pay_minor']),
                $this->decimal($product['sold_cost_minor']),
                $this->decimal($product['returned_cost_minor']),
                $this->decimal($product['net_cost_minor']),
                (string) $product['covered_cost_quantity'],
                (string) $product['missing_cost_quantity'],
                (string) $product['fallback_cost_quantity'],
                null === $product['cost_coverage_percent'] ? '' : (string) $product['cost_coverage_percent'],
                $this->costStatusLabel((string) $product['cost_status']),
                null === $product['result_minor'] ? '' : $this->decimal($product['result_minor']),
                null === $product['profitability_percent'] ? '' : (string) $product['profitability_percent'],
                null === $product['return_rate_percent'] ? '' : (string) $product['return_rate_percent'],
            ]);
        }

        $this->writeCsvRow($stream, []);
        $this->writeCsvRow($stream, ['reportId', 'Дата с', 'Дата по', 'Строк', 'Расчётное перечисление']);
        foreach ($report['reports'] as $row) {
            $this->writeCsvRow($stream, [
                $this->spreadsheetSafe((string) $row['report_id']),
                $row['date_from'],
                $row['date_to'],
                (string) $row['row_count'],
                $this->decimal($row['payout_minor']),
            ]);
        }

        $this->writeCsvRow($stream, []);
        $this->writeCsvRow($stream, ['Операция WB', 'Тип документа', 'Строк', 'Отчётов', 'Влияние на перечисление']);
        foreach ($report['operations'] as $row) {
            $this->writeCsvRow($stream, [
                $this->spreadsheetSafe((string) $row['operation_name']),
                $this->spreadsheetSafe((string) $row['doc_type']),
                (string) $row['row_count'],
                (string) $row['report_count'],
                $this->decimal($row['payout_minor']),
            ]);
        }

        $this->writeCsvRow($stream, []);
        $this->writeCsvRow($stream, ['Проверка качества', 'Количество']);
        foreach ($report['quality_labels'] as $key => $label) {
            $this->writeCsvRow($stream, [$label, (string) $report['quality'][$key]]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if (false === $csv) {
            throw new \RuntimeException('Не удалось сформировать CSV отчёт.');
        }

        return $csv;
    }

    /**
     * @param resource $stream
     * @param list<string> $fields
     */
    private function writeCsvRow($stream, array $fields): void
    {
        if (false === fputcsv($stream, $fields, ';', '"', '')) {
            throw new \RuntimeException('Не удалось записать строку CSV отчёта.');
        }
    }

    private function decimal(int $amountMinor): string
    {
        return Money::fromMinor($amountMinor, 'RUB')->toDecimalString();
    }

    private function spreadsheetSafe(string $value): string
    {
        return 1 === preg_match('/^[=+\-@]/u', ltrim($value)) ? "'".$value : $value;
    }

    private function mappingStatusLabel(string $status): string
    {
        return self::MAPPING_STATUS_LABELS[$status] ?? 'Неизвестно';
    }

    private function costStatusLabel(string $status): string
    {
        return self::COST_STATUS_LABELS[$status] ?? 'Неизвестно';
    }
}
