<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/** Компания помечена к удалению, пока шёл вход в банк: подключение не сохраняется. */
final class BankCompanyDeletionRequestedException extends \RuntimeException
{
    public function __construct(string $companyId)
    {
        parent::__construct(sprintf('Company %s is scheduled for deletion; bank connection is not saved.', $companyId));
    }
}
