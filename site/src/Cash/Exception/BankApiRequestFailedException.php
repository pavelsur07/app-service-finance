<?php

declare(strict_types=1);

namespace App\Cash\Exception;

/**
 * Постоянный отказ API банка: 4xx кроме 408 и 429 (неверный ключ, нет доступа,
 * неверный запрос) или ответ, который нельзя разобрать. Повтор не поможет.
 *
 * Тело ответа банка в сообщение не попадает: в нём могут быть данные клиента.
 * Исключения HttpClient не принимаются как previous: их сообщения несут
 * полный URL с query, а в query — номер счёта.
 */
final class BankApiRequestFailedException extends \RuntimeException
{
    public function __construct(
        private readonly string $bankCode,
        private readonly ?int $statusCode,
    ) {
        parent::__construct(
            null === $statusCode
                ? sprintf('Bank API "%s" returned an unreadable response.', $bankCode)
                : sprintf('Bank API "%s" rejected the request, status %d.', $bankCode, $statusCode),
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
