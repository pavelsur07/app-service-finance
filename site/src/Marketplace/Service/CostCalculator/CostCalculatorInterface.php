<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceCostOperationType;

interface CostCalculatorInterface
{
    /**
     * Проверяет, может ли этот калькулятор обработать данную запись
     */
    public function supports(array $item): bool;

    /**
     * Нужен ли listing для расчёта (false = общие затраты без привязки к товару)
     */
    public function requiresListing(): bool;

    /**
     * Рассчитывает затраты из записи
     *
     * @return array<int, array{
     *     category_code: string,
     *     amount: string,
     *     external_id: string,
     *     cost_date: \DateTimeImmutable,
     *     description: string|null,
     *     operation_type?: MarketplaceCostOperationType,
     *     product: null
     * }>
     */
    public function calculate(array $item, ?MarketplaceListing $listing): array;
}
