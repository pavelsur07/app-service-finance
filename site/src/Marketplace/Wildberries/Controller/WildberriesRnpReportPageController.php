<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Controller;

use App\Marketplace\Wildberries\Service\WildberriesRnpReportService;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class WildberriesRnpReportPageController extends AbstractController
{
    public function __construct(
        private readonly WildberriesRnpReportService $reportService,
        private readonly ActiveCompanyService $companyContext,
    ) {
    }

    #[Route(path: '/wb/reports/rnp', name: 'wb_reports_rnp', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyContext->getActiveCompany();
        $period = (string) $request->query->get('period', 'month');
        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');

        $errors = [];

        try {
            if ('custom' === $period) {
                if (null === $fromParam || null === $toParam) {
                    throw new \InvalidArgumentException('Укажите обе даты периода.');
                }

                $from = $this->parseDate((string) $fromParam, 'Дата с');
                $to = $this->parseDate((string) $toParam, 'Дата по');
            } else {
                try {
                    ['from' => $from, 'to' => $to] = $this->reportService->resolvePeriod($period);
                } catch (\InvalidArgumentException) {
                    ['from' => $from, 'to' => $to] = $this->reportService->resolvePeriod('month');
                    $errors[] = 'Выбран неподдерживаемый период, показан отчёт за месяц.';
                    $period = 'month';
                }
            }

            if ($from > $to) {
                throw new \InvalidArgumentException('Дата начала должна быть не позже даты окончания.');
            }
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
            ['from' => $from, 'to' => $to] = $this->reportService->resolvePeriod('month');
            $period = 'month';
        }

        $filtersInput = [
            'sku' => $this->stringifyFilterValue($request->query->get('sku')),
            'brand' => $this->stringifyFilterValue($request->query->get('brand')),
            'category' => $this->stringifyFilterValue($request->query->get('category')),
        ];

        $filters = [
            'sku' => $this->splitFilterValues($filtersInput['sku']),
            'brand' => $this->splitFilterValues($filtersInput['brand']),
            'category' => $this->splitFilterValues($filtersInput['category']),
        ];

        $report = $this->reportService->buildReport($company, $from, $to, $filters);

        $exportParams = $request->query->all();
        if ('custom' === $period) {
            unset($exportParams['period']);
            $exportParams['from'] = $from->format('Y-m-d');
            $exportParams['to'] = $to->format('Y-m-d');
        } else {
            $exportParams['period'] = $period;
            unset($exportParams['from'], $exportParams['to']);
        }

        return $this->render('wildberries/rnp/index.html.twig', [
            'company' => $company,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'filters_input' => $filtersInput,
            'report' => $report,
            'errors' => $errors,
            'export_params' => $exportParams,
        ]);
    }

    private function parseDate(string $value, string $name): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value) ?: false;
        if (false === $date) {
            throw new \InvalidArgumentException(sprintf('Некорректная дата "%s". Ожидается формат ГГГГ-ММ-ДД.', $name));
        }

        return $date->setTime(0, 0);
    }

    /**
     * @return list<string>
     */
    private function splitFilterValues(string $value): array
    {
        $value = trim($value);
        if ('' === $value) {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $value) ?: [];

        $result = [];
        foreach ($parts as $part) {
            $normalized = trim($part);
            if ('' !== $normalized) {
                $result[] = $normalized;
            }
        }

        return array_values(array_unique($result));
    }

    private function stringifyFilterValue($value): string
    {
        if (\is_array($value)) {
            return trim(implode(' ', array_map(static fn ($item): string => (string) $item, $value)));
        }

        return trim((string) $value);
    }
}
