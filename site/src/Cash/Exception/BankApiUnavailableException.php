<?php

declare(strict_types=1);

namespace App\Cash\Exception;

/**
 * Временный сбой API банка: сеть, 408, 429, 5xx, обрыв тела. Повтор может помочь.
 *
 * Тело ответа банка в сообщение не попадает: в нём могут быть данные клиента.
 * Исключения HttpClient не принимаются как previous: их сообщения несут
 * полный URL с query, а в query — номер счёта.
 */
final class BankApiUnavailableException extends \RuntimeException
{
    public function __construct(
        private readonly string $bankCode,
        private readonly ?int $statusCode,
    ) {
        parent::__construct(
            null === $statusCode
                ? sprintf('Bank API "%s" is unreachable.', $bankCode)
                : sprintf('Bank API "%s" is temporarily unavailable, status %d.', $bankCode, $statusCode),
        );
    }

    public function getBankCode(): string
    {
        return $this->bankCode;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }
}
