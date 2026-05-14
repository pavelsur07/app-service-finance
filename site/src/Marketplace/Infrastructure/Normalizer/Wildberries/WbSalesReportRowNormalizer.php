<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Normalizer\Wildberries;

final readonly class WbSalesReportRowNormalizer
{
    public function rrdId(array $row): ?string
    {
        return $this->nullableString($row, 'rrdId', 'rrd_id');
    }

    public function srid(array $row): ?string
    {
        return $this->nullableString($row, 'srid');
    }

    public function docTypeName(array $row): string
    {
        return $this->string($row, 'docTypeName', 'doc_type_name');
    }

    public function sellerOperName(array $row): string
    {
        return $this->string($row, 'sellerOperName', 'supplier_oper_name');
    }

    public function nmId(array $row): string
    {
        return $this->string($row, 'nmId', 'nm_id');
    }

    public function vendorCode(array $row): string
    {
        return $this->string($row, 'vendorCode', 'sa_name');
    }

    public function techSize(array $row): ?string
    {
        return $this->nullableString($row, 'techSize', 'ts_name');
    }

    public function barcode(array $row): ?string
    {
        return $this->nullableString($row, 'sku', 'barcode');
    }

    public function quantity(array $row): int
    {
        return (int) ($this->raw($row, 'quantity') ?? 0);
    }

    public function retailPrice(array $row): float
    {
        return $this->float($row, 'retailPrice', 'retail_price');
    }

    public function retailAmount(array $row): float
    {
        return $this->float($row, 'retailAmount', 'retail_amount');
    }

    public function retailPriceWithDisc(array $row): float
    {
        return $this->float($row, 'retailPriceWithDisc', 'retail_price_withdisc_rub');
    }

    public function forPay(array $row): float
    {
        return $this->float($row, 'forPay', 'ppvz_for_pay');
    }

    public function acquiringFee(array $row): float
    {
        return $this->float($row, 'acquiringFee', 'acquiring_fee');
    }

    public function reportDate(array $row): \DateTimeImmutable
    {
        $date = $this->nullableString($row, 'rrDate', 'rr_dt');
        if ($date === null) {
            throw new \InvalidArgumentException('WB report row must contain rrDate or rr_dt.');
        }

        return new \DateTimeImmutable($date);
    }

    public function operationDate(array $row): \DateTimeImmutable
    {
        $date = $this->nullableString($row, 'saleDt', 'sale_dt', 'rrDate', 'rr_dt');
        if ($date === null) {
            throw new \InvalidArgumentException('WB report row must contain saleDt/sale_dt or rrDate/rr_dt.');
        }

        return new \DateTimeImmutable($date);
    }

    public function isSale(array $row): bool
    {
        return $this->normalizeDocTypeName($this->docTypeName($row)) === 'sale';
    }

    public function isReturn(array $row): bool
    {
        return $this->normalizeDocTypeName($this->docTypeName($row)) === 'return';
    }

    public function isSaleOrReturn(array $row): bool
    {
        return $this->isSale($row) || $this->isReturn($row);
    }

    public function fullMarketplaceCommission(array $row): float
    {
        return $this->retailPriceWithDisc($row) - $this->forPay($row) - $this->acquiringFee($row);
    }

    private function raw(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
    }

    private function string(array $row, string ...$keys): string
    {
        return trim((string) ($this->raw($row, ...$keys) ?? ''));
    }

    private function nullableString(array $row, string ...$keys): ?string
    {
        $value = $this->string($row, ...$keys);

        return $value === '' ? null : $value;
    }

    private function float(array $row, string ...$keys): float
    {
        return (float) ($this->raw($row, ...$keys) ?? 0);
    }

    private function normalizeDocTypeName(string $value): ?string
    {
        return match (mb_strtolower(trim($value))) {
            'продажа', 'sale' => 'sale',
            'возврат', 'return' => 'return',
            default => null,
        };
    }
}

