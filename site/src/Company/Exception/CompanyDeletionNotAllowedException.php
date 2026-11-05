<?php

declare(strict_types=1);

namespace App\Company\Exception;

/** Удалять или восстанавливать компанию может только её владелец. */
final class CompanyDeletionNotAllowedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Удалить или восстановить компанию может только её владелец.');
    }
}
