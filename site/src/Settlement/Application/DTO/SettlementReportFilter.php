<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

final readonly class SettlementReportFilter
{
    public function __construct(public \DateTimeImmutable $from, public \DateTimeImmutable $to, public string $currency = 'RUB', public ?string $counterpartyId = null, public int $page = 1, public int $perPage = 50)
    {
        if ($from->format('Y-m-d') > $to->format('Y-m-d') || 'RUB' !== $currency || $page < 1 || !in_array($perPage, [25, 50, 100], true) || $page > intdiv(\PHP_INT_MAX, $perPage)) {
            throw new \DomainException('Некорректный период, валюта или страница отчёта.');
        }
        if (null !== $counterpartyId && !\Ramsey\Uuid\Uuid::isValid($counterpartyId)) {
            throw new \DomainException('Некорректный контрагент.');
        }
    }
}
