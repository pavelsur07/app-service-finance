<?php

declare(strict_types=1);

namespace App\ServiceGateway\Exception;

/**
 * Ответ сервиса не прошёл проверку контракта (`services/_contracts`): данные
 * не принимаются (docs/architecture/service-gateway.md §2.4).
 */
final class ContractViolationException extends \RuntimeException
{
    public static function at(string $contract, string $path, string $reason): self
    {
        return new self(sprintf('Contract %s violated at %s: %s', $contract, $path, $reason));
    }
}
