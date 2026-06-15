<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Ozon;

enum OzonCredentialValidationStatus: string
{
    case VALID = 'valid';
    case INVALID_CREDENTIALS = 'invalid_credentials';
    case TEMPORARY_ERROR = 'temporary_error';
    case RATE_LIMITED = 'rate_limited';
    case UNEXPECTED_ERROR = 'unexpected_error';
}
