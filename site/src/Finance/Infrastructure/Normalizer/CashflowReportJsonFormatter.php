<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Normalizer;

final readonly class CashflowReportJsonFormatter
{
    /**
     * @param array<string,mixed> $payload Payload from CashflowReportBuilder::build()
     * @param array{
     *     include_exported_at?: bool,
     *     exported_at?: \DateTimeInterface,
     *     dataset?: string,
     *     include_filters?: bool,
     * } $options
     *
     * @return array<string,mixed>
     */
    public function format(array $payload, array $options = []): array
    {
        $dateFrom = $this->formatDate($payload['date_from']);
        $dateTo = $this->formatDate($payload['date_to']);

        $result = [];
        if (($options['include_exported_at'] ?? false) || isset($options['exported_at'])) {
            $exportedAt = $options['exported_at'] ?? new \DateTimeImmutable();
            $result['exported_at'] = $exportedAt->format(\DateTimeInterface::ATOM);
        }
        if (isset($options['dataset'])) {
            $result['dataset'] = $options['dataset'];
        }
        if ($options['include_filters'] ?? false) {
            $result['filters'] = [
                'group' => $payload['group'],
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ];
        }

        return $result + [
            'company' => $payload['company']->getId(),
            'group' => $payload['group'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'periods' => array_map(fn (array $period): array => [
                'start' => $this->formatDate($period['start']),
                'end' => $this->formatDate($period['end']),
                'label' => $period['label'],
            ], $payload['periods']),
            'categories' => array_map(static fn ($category): array => [
                'id' => $category->getId(),
                'name' => $category->getName(),
            ], $payload['categories']),
            'categoryTotals' => $this->formatCategoryTotals($payload['categoryTotals']),
            'openings' => $payload['openings'],
            'closings' => $payload['closings'],
            'tree' => $payload['tree'],
            'categoryTree' => $payload['categoryTree'],
        ];
    }

    private function formatDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    /**
     * @param array<string,array<string,mixed>> $categoryTotals
     *
     * @return array<string,array{totals:mixed}>
     */
    private function formatCategoryTotals(array $categoryTotals): array
    {
        $formatted = [];
        foreach ($categoryTotals as $categoryId => $row) {
            $formatted[$categoryId] = [
                'totals' => $row['totals'],
            ];
        }

        return $formatted;
    }
}
