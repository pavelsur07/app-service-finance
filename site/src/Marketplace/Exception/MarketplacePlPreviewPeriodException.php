<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class MarketplacePlPreviewPeriodException extends \RuntimeException
{
    public const string CODE = 'invalid_period';

    public static function reversed(): self
    {
        return new self('Начало периода позже окончания.');
    }

    public static function tooLong(int $maxDays): self
    {
        return new self(sprintf('Период предпросмотра — не длиннее %d дней.', $maxDays));
    }
}
