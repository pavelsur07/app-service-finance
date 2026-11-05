<?php

declare(strict_types=1);

namespace App\Company\Exception;

/** Введённое для подтверждения название не совпало с названием компании. */
final class CompanyDeletionConfirmationMismatchException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Название не совпадает. Введите название компании точно так, как оно указано.');
    }
}
