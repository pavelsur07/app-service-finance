<?php

declare(strict_types=1);

namespace App\Cash\Exception;

final class CashDocumentOperationException extends \RuntimeException implements CashApiExceptionInterface, \App\Shared\Domain\Exception\UserFacingException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'cash_document_operation_not_allowed';
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
