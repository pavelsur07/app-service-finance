<?php

declare(strict_types=1);

namespace App\Balance\Domain\Policy;

use App\Balance\Exception\BalanceLedgerException;
use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Intl\Currencies;

/** Exact validation around the shared Money parser (which otherwise rounds). */
final class LedgerAmount
{
    public static function minor(string $decimal, string $currency, bool $allowZero = false): string
    {
        if (!Currencies::exists($currency)) {
            throw new BalanceLedgerException('Неизвестная валюта учета.');
        }
        $decimal = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], trim($decimal));
        $digits = Currencies::getFractionDigits($currency);
        if (!preg_match('/^\d+(?:\.(\d+))?$/D', $decimal, $matches) || strlen($matches[1] ?? '') > $digits || bccomp($decimal, '0', $digits) < ($allowZero ? 0 : 1)) {
            throw new BalanceLedgerException('Сумма должна быть положительной и иметь точность валюты учета.');
        }
        self::assertRange(bcmul($decimal, bcpow('10', (string) $digits, 0), 0));

        return (string) Money::fromString($decimal, $currency)->amountMinor();
    }

    public static function decimal(string $minor, string $currency): string
    {
        if (!preg_match('/^-?\d+$/D', $minor) || !Currencies::exists($currency)) {
            throw new BalanceLedgerException('Некорректная сумма или валюта.');
        }
        self::assertRange($minor);

        return Money::fromMinor((int) $minor, $currency)->toDecimalString();
    }

    public static function assertRange(string $minor): void
    {
        if (bccomp($minor, (string) \PHP_INT_MAX, 0) > 0 || bccomp($minor, (string) \PHP_INT_MIN, 0) < 0) {
            throw new BalanceLedgerException('Сумма выходит за допустимый диапазон.');
        }
    }

    public static function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Moscow'));
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new BalanceLedgerException('Укажите корректную дату в формате ГГГГ-ММ-ДД.');
        }

        return $date;
    }
}
