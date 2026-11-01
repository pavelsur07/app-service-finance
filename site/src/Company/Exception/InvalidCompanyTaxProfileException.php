<?php

declare(strict_types=1);

namespace App\Company\Exception;

final class InvalidCompanyTaxProfileException extends \RuntimeException
{
    public static function vatModeNotAllowed(string $taxRegime, string $vatMode): self
    {
        return new self(sprintf('Режим «%s» не допускает «%s».', $taxRegime, $vatMode));
    }

    public static function validFromNotFirstDay(): self
    {
        return new self('Налоговый режим меняется с 1-го числа месяца.');
    }
}
