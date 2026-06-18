<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

final class OzonMoneyParser
{
    public function minor(mixed $value): int
    {
        $decimal = $this->decimal($value);
        $negative = str_starts_with($decimal, '-');
        if ($negative) {
            $decimal = substr($decimal, 1);
        }

        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $fraction = str_pad(preg_replace('/\D/', '', $fraction) ?? '', 3, '0');
        $kopecks = ((int) $integer) * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            ++$kopecks;
        }

        return $negative ? -$kopecks : $kopecks;
    }

    private function decimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return sprintf('%.6F', $value);
        }

        if (is_string($value)) {
            $normalized = str_replace(',', '.', trim($value));
            if (preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
                return $normalized;
            }
        }

        return '0';
    }
}
