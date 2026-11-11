<?php

declare(strict_types=1);

namespace App\Cash\Exception;

use App\Shared\Domain\Exception\UserFacingException;

final class InvalidCashImportFileException extends \RuntimeException implements UserFacingException
{
}
