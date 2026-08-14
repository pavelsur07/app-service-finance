<?php

declare(strict_types=1);

namespace App\Balance\Exception;

final class BalanceLinkNotFoundException extends \DomainException
{
    public function __construct(string $linkId)
    {
        parent::__construct(sprintf('Связь баланса %s не найдена.', $linkId));
    }
}
