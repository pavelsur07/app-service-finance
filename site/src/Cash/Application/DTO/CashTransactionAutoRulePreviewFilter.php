<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CashTransactionAutoRulePreviewFilter
{
    private const MAX_DAYS = 366;
    private const MIN_LIMIT = 10;
    private const MAX_LIMIT = 200;

    private function __construct(
        public \DateTimeImmutable $dateFrom,
        public \DateTimeImmutable $dateTo,
        public int $limit,
    ) {
    }

    public static function fromStrings(string $dateFrom, string $dateTo, string $limit): self
    {
        $parsedDateFrom = self::parseDate($dateFrom);
        $parsedDateTo = self::parseDate($dateTo);

        if (null === $parsedDateFrom || null === $parsedDateTo) {
            throw new \InvalidArgumentException('Укажите даты в формате ГГГГ-ММ-ДД.');
        }

        if ($parsedDateFrom > $parsedDateTo) {
            throw new \InvalidArgumentException('Начальная дата не может быть позже конечной.');
        }

        if ((int) $parsedDateFrom->diff($parsedDateTo)->days + 1 > self::MAX_DAYS) {
            throw new \InvalidArgumentException('Период проверки не может превышать 366 дней.');
        }

        if (1 !== preg_match('/^\d+$/D', $limit)) {
            throw new \InvalidArgumentException('Лимит должен быть целым положительным числом.');
        }

        return new self(
            $parsedDateFrom,
            $parsedDateTo,
            max(self::MIN_LIMIT, min((int) $limit, self::MAX_LIMIT)),
        );
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $parsed && $parsed->format('Y-m-d') === $value ? $parsed : null;
    }
}
