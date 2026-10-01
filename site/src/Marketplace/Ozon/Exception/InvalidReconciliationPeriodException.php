<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Exception;

final class InvalidReconciliationPeriodException extends \RuntimeException
{
    public static function notWithinOneMonth(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        return new self(sprintf('Период сверки должен лежать в одном календарном месяце: %s — %s.', $from->format('Y-m-d'), $to->format('Y-m-d')));
    }
}
