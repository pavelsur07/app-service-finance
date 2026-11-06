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
        if (!is_numeric($normalized) || (float) $normalized <= 0 || (float) $normalized > self::MAX_LIMIT_RUB) {
            throw new InvalidAiCompanyLimitsException(sprintf('Лимит — число больше 0 и не больше %s ₽.', number_format(self::MAX_LIMIT_RUB, 0, ',', ' ')));
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    public static function lookback(int $days): int
    {
        if (!\in_array($days, AiCompanyAccess::LOOKBACK_CHOICES, true)) {
            throw new InvalidAiCompanyLimitsException('Глубина выгрузки — 7, 30 или 90 дней.');
        }

        return $days;
    }
}
