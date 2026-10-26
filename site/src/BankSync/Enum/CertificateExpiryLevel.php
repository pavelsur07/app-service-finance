<?php

declare(strict_types=1);

namespace App\BankSync\Enum;

/**
 * Насколько близко истечение сертификата канала банка. Пороги — `CertificateExpiryPolicy`.
 */
enum CertificateExpiryLevel: string
{
    case OK = 'ok';
    /** Пора выпускать замену. */
    case WARNING = 'warning';
    /** Истёк или замену уже не успеть выпустить в срок банка. */
    case ERROR = 'error';
}
