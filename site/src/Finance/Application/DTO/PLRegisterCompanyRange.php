<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

/**
 * Окно, в котором у компании есть активные документы ОПиУ или строки регистра.
 */
final readonly class PLRegisterCompanyRange
{
    public function __construct(
        public string $companyId,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
    }
}
