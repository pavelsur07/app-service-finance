<?php

declare(strict_types=1);

namespace App\Billing\Controller\Admin;

use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Enum\TariffCode;
use App\Billing\Exception\InvalidBillingInputException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Разбор полей форм страницы тарифа кабинета: даты (Y-m-d по Москве), код тарифа, сумма в рублях.
 */
final class AdminBillingForm
{
    public static function tariff(Request $request): TariffCode
    {
        return TariffCode::tryFrom($request->request->getString('tariff'))
            ?? throw new InvalidBillingInputException('Выберите тариф.');
    }

    public static function date(Request $request, string $field, string $label): \DateTimeImmutable
    {
        return self::optionalDate($request, $field, $label)
            ?? throw new InvalidBillingInputException(sprintf('Укажите %s.', $label));
    }

    public static function optionalDate(Request $request, string $field, string $label): ?\DateTimeImmutable
    {
        $value = trim($request->request->getString($field));
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(PaidPeriodCalculator::TIMEZONE));
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidBillingInputException(sprintf('Неверный формат: %s.', $label));
        }

        return $date;
    }

    /**
     * «4970», «4 970,50», «4970.5» → копейки; без float.
     */
    public static function amountKopecks(Request $request): int
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($request->request->getString('amount')));
        if (1 !== preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidBillingInputException('Сумма — число в рублях, до двух знаков после запятой.');
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    public static function months(Request $request): int
    {
        $value = $request->request->getString('months');
        if (1 !== preg_match('/^\d{1,2}$/', $value)) {
            throw new InvalidBillingInputException('Выберите срок оплаты.');
        }

        return (int) $value;
    }
}
