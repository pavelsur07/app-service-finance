<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use Ramsey\Uuid\Uuid;

final class OzonOperationKey
{
    public function baseExternalId(array $row): string
    {
        $operationId = trim((string) ($row['operation_id'] ?? ''));
        if ('' !== $operationId) {
            return sprintf('ozon:operation:%s', $operationId);
        }

        $postingNumber = trim((string) ($row['posting']['posting_number'] ?? $row['posting_number'] ?? ''));
        $date = trim((string) ($row['operation_date'] ?? $row['sale_date'] ?? $row['return_date'] ?? $row['report_date'] ?? $row['_header']['stop_date'] ?? 'unknown-date'));
        $sku = trim((string) ($row['item']['sku'] ?? ($row['items'][0]['sku'] ?? $row['sku'] ?? $row['offer_id'] ?? 'unknown-sku')));

        return sprintf('ozon:fallback:%s:%s:%s', $postingNumber ?: 'unknown-posting', $sku, $date);
    }

    public function transactionExternalId(array $row, string $component): string
    {
        return sprintf('%s:%s', $this->baseExternalId($row), $this->normalizeComponent($component));
    }

    public function operationGroupId(string $companyId, array $row): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:%s', $companyId, $this->baseExternalId($row)))->toString();
    }

    private function normalizeComponent(string $component): string
    {
        $normalized = strtolower(trim($component));
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? 'component';
        $normalized = trim($normalized, '_');

        return '' !== $normalized ? $normalized : 'component';
    }
}
