<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Service;

use App\ServiceGateway\Entity\AiCompanyAccess;
use App\ServiceGateway\Exception\InvalidAiCompanyLimitsException;

/**
 * Разбор лимита (₽, допускает запятую) и глубины выгрузки из формы админки.
 */
final readonly class AiCompanyLimits
{
    public const MAX_LIMIT_RUB = 1_000_000;

    /** @return numeric-string лимит с двумя знаками */
    public static function limit(string $raw): string
    {
        $normalized = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($raw));
        // Проверка — после округления до копеек: «0,001» дало бы 0.00 и нарушило CHECK в БД.
        $rounded = is_numeric($normalized) ? round((float) $normalized, 2) : 0.0;
        if ($rounded <= 0 || $rounded > self::MAX_LIMIT_RUB) {
            throw new InvalidAiCompanyLimitsException(sprintf('Лимит — число больше 0 и не больше %s ₽.', number_format(self::MAX_LIMIT_RUB, 0, ',', ' ')));
        }

        return number_format($rounded, 2, '.', '');
    }

    public static function lookback(int $days): int
    {
        if (!\in_array($days, AiCompanyAccess::LOOKBACK_CHOICES, true)) {
            throw new InvalidAiCompanyLimitsException('Глубина выгрузки — 7, 30 или 90 дней.');
        }

        return $days;
    }
}
