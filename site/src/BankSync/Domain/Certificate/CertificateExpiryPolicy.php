<?php

declare(strict_types=1);

namespace App\BankSync\Domain\Certificate;

use App\BankSync\Enum\CertificateExpiryLevel;

/**
 * Сроки сертификатов канала банка (клиентский и CA серверов). Предупреждение — за 30
 * дней: банк пишет о прод-сертификате за месяц. Ошибка — за 14 календарных дней:
 * замену банк требует за 10 рабочих.
 */
final readonly class CertificateExpiryPolicy
{
    public const WARNING_DAYS = 30;
    public const ERROR_DAYS = 14;

    /**
     * Полных суток до истечения; истёкший — отрицательное число.
     */
    public function daysLeft(\DateTimeImmutable $notAfter, \DateTimeImmutable $now): int
    {
        return (int) floor(($notAfter->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    public function level(\DateTimeImmutable $notAfter, \DateTimeImmutable $now): CertificateExpiryLevel
    {
        if ($notAfter <= $now) {
            return CertificateExpiryLevel::ERROR;
        }

        $daysLeft = $this->daysLeft($notAfter, $now);

        return match (true) {
            $daysLeft <= self::ERROR_DAYS => CertificateExpiryLevel::ERROR,
            $daysLeft <= self::WARNING_DAYS => CertificateExpiryLevel::WARNING,
            default => CertificateExpiryLevel::OK,
        };
    }
}
