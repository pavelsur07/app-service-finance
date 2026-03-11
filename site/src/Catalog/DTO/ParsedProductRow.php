<?php

declare(strict_types=1);

namespace App\Catalog\DTO;

/**
 * Результат парсинга одной строки XLS-файла.
 * Все поля nullable — валидация в ImportProductsFromXlsAction.
 */
final class ParsedProductRow
{
    /**
     * @param int         $rowNumber   Номер строки в файле (для отчёта об ошибках)
     * @param string|null $name        Наименование товара
     * @param string|null $vendorSku   Артикул продавца (внешний)
     * @param string|null $barcodes    Баркод(ы) через ";" или ","
     * @param string|null $priceAmount Закупочная цена (может быть "0")
     * @param string|null $currency    Валюта ISO 4217 (RUB, USD, EUR)
     */
    public function __construct(
        public readonly int     $rowNumber,
        public readonly ?string $name,
        public readonly ?string $vendorSku,
        public readonly ?string $barcodes,
        public readonly ?string $priceAmount,
        public readonly ?string $currency,
    ) {
    }

    /**
     * Разбивает строку баркодов на массив отдельных значений.
     * Поддерживаемые разделители: ";" и ","
     *
     * @return string[]
     */
    public function parseBarcodes(): array
    {
        if (null === $this->barcodes || '' === trim($this->barcodes)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[;,]/', $this->barcodes) ?: []),
            static fn(string $b) => '' !== $b,
        ));
    }

    public function hasPrice(): bool
    {
        return null !== $this->priceAmount && '' !== trim($this->priceAmount);
    }

    /**
     * Возвращает валюту из строки или RUB по умолчанию.
     */
    public function resolvedCurrency(): string
    {
        $currency = trim((string) $this->currency);

        return 3 === mb_strlen($currency) ? strtoupper($currency) : 'RUB';
    }
}
