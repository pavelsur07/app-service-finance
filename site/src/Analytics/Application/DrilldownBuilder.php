<?php

namespace App\Analytics\Application;

use App\Analytics\Domain\DrilldownKey;

final class DrilldownBuilder
{
    /**
     * @param array<string, mixed> $params
     *
     * @return array{key: string, params: array<string, mixed>}
     */
    public function cashTransactions(array $params): array
    {
        return [
            'key' => DrilldownKey::CASH_TRANSACTIONS,
            'params' => $params,
        ];
    }

    /**
     * @return array{key: string, params: array<string, mixed>}
     */
    public function cashBalances(string $at): array
    {
        return [
            'key' => DrilldownKey::CASH_BALANCES,
            'params' => [
                'at' => $at,
            ],
        ];
    }

    /**
     * @return array{key: string, params: array<string, mixed>}
     */
    public function fundsReserved(string $at): array
    {
        return [
            'key' => DrilldownKey::FUNDS_RESERVED,
            'params' => [
                'at' => $at,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{key: string, params: array<string, mixed>}
     */
    public function plDocuments(array $params): array
    {
        return [
            'key' => DrilldownKey::PL_DOCUMENTS,
            'params' => $params,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{key: string, params: array<string, mixed>}
     */
    public function plReport(array $params): array
    {
        return [
            'key' => DrilldownKey::PL_REPORT,
            'params' => $params,
        ];
    }
}
