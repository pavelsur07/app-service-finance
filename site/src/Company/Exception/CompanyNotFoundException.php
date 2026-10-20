<?php

declare(strict_types=1);

namespace App\Company\Exception;

final class CompanyNotFoundException extends \RuntimeException
{
    public function __construct(string $companyId)
    {
        parent::__construct(\sprintf('Компания %s не найдена.', $companyId));
    }
}
