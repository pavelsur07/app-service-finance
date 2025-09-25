<?php

namespace App\Service;

class AccountMasker
{
    public function mask(mixed $accountNumber): ?string
    {
        if (null === $accountNumber) {
            return null;
        }

        if (!is_string($accountNumber)) {
            if (is_numeric($accountNumber)) {
                $accountNumber = (string) $accountNumber;
            } else {
                return null;
            }
        }

        $normalized = preg_replace('/\s+/', '', $accountNumber);
        if (null === $normalized || '' === $normalized) {
            return $accountNumber;
        }

        $length = mb_strlen($normalized);
        if (0 === $length) {
            return $accountNumber;
        }

        if ($length <= 10) {
            return trim(chunk_split($normalized, 4, ' '));
        }

        $prefixLength = min(6, $length);
        $suffixLength = min(4, max(0, $length - $prefixLength));
        $maskedLength = $length - $prefixLength - $suffixLength;

        if ($maskedLength <= 0) {
            return trim(chunk_split($normalized, 4, ' '));
        }

        $prefix = mb_substr($normalized, 0, $prefixLength);
        $suffix = $suffixLength > 0 ? mb_substr($normalized, -$suffixLength) : '';
        $masked = $prefix.str_repeat('•', $maskedLength).$suffix;

        return trim(chunk_split($masked, 4, ' '));
    }
}
