<?php

declare(strict_types=1);

namespace App\Shared\Security\Spa;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class SpaHttpException extends HttpException
{
    public function __construct(int $status, public readonly string $errorCode, string $message)
    {
        if ($status < 400 || $status >= 500) {
            throw new \InvalidArgumentException('SPA client exceptions must be 4xx.');
        }
        parent::__construct($status, $message);
    }
}
