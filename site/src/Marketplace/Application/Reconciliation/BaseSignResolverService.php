<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Reconciliation;

/**
 * Шаг 2: вычисляет baseSign для каждого typeName.
 *
 * baseSign = '+' если positiveCount >= negativeCount (исключая нули)
 * baseSign = '-' если negativeCount > positiveCount
 * Если только нули — baseSign = '-' по умолчанию.
 */
final class BaseSignResolverService
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, string> Map<typeName, baseSign>
     */
    public function resolve(array $rows): array
    {
        $positiveCount = [];
        $negativeCount = [];

        foreach ($rows as $row) {
            $typeName = $row['typeName'];
            $amount   = (float) $row['amount'];

            if ($amount == 0) {
                continue;
            }

            if ($amount > 0) {
                $positiveCount[$typeName] = ($positiveCount[$typeName] ?? 0) + 1;
            } else {
                $negativeCount[$typeName] = ($negativeCount[$typeName] ?? 0) + 1;
            }
        }

        $allTypes = array_unique(array_column($rows, 'typeName'));
        $result   = [];

        foreach ($allTypes as $typeName) {
            $pos = $positiveCount[$typeName] ?? 0;
            $neg = $negativeCount[$typeName] ?? 0;

            $result[$typeName] = $pos >= $neg ? '+' : '-';
        }

        return $result;
    }
}
