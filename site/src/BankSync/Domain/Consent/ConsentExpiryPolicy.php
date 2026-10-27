<?php

declare(strict_types=1);

namespace App\BankSync\Domain\Consent;

use App\BankSync\Enum\ConsentState;

/**
 * Что делать с подключением по согласиям его пользователя у банка.
 *
 * - Действующее (активное и последний день не прошёл) есть — срок по самому раннему из
 *   них: у пользователя может быть несколько организаций, а какая наша, банк не говорит;
 *   ранняя дата — осторожная.
 * - Согласия есть, но все неактивны или истекли — `EXPIRED`, нужен новый вход.
 * - Согласий нет — `NOT_FOUND`: пустой или урезанный ответ банка не должен гасить
 *   подключения.
 */
final readonly class ConsentExpiryPolicy
{
    public const WARNING_DAYS = 30;

    /**
     * @param list<BankConsent> $consents согласия одного пользователя
     */
    public function decide(array $consents, \DateTimeImmutable $today): ConsentDecision
    {
        if ([] === $consents) {
            return new ConsentDecision(ConsentState::NOT_FOUND);
        }

        // Календарные дни без часового пояса: дата банка — день, сегодня — день часов приложения.
        $day = self::calendarDay($today);
        $earliest = null;
        foreach ($consents as $consent) {
            $expiresOn = self::calendarDay($consent->expiresOn);
            if ($consent->isActive && $expiresOn >= $day && (null === $earliest || $expiresOn < $earliest)) {
                $earliest = $expiresOn;
            }
        }
        if (null === $earliest) {
            return new ConsentDecision(ConsentState::EXPIRED);
        }

        $daysLeft = (int) $day->diff($earliest)->days;

        return new ConsentDecision(ConsentState::ACTIVE, $earliest, $daysLeft <= self::WARNING_DAYS);
    }

    private static function calendarDay(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
