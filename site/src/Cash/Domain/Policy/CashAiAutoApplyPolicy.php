<?php

declare(strict_types=1);

namespace App\Cash\Domain\Policy;

use App\Cash\Application\DTO\CashAiAutoApplyGateStats;

/**
 * Когда ИИ-подсказка статьи ДДС применяется без человека (docs/tasks/ai/stages/stage-2.md, 2.3).
 *
 * Ворота компании: флаг включён и люди достаточно раз решили по уверенным подсказкам этой
 * компании с достаточной точностью. Подсказка: уверенность не ниже порога и вердикт сервиса
 * `accepted` (review-проверки, например несовпадение направления, — только человеку).
 *
 * Пороги — открытый вопрос ТЗ §12: значения консервативные, до решения Владельца.
 */
final class CashAiAutoApplyPolicy
{
    public const MIN_CONFIDENCE = 0.90;
    public const MIN_DECISIONS = 100;
    public const MIN_PRECISION = 0.95;

    public const REASON_DISABLED = 'disabled';
    public const REASON_TOO_FEW_DECISIONS = 'too_few_decisions';
    public const REASON_LOW_PRECISION = 'low_precision';

    /**
     * @return string|null причина отказа; null — ворота открыты
     */
    public static function gateClosedReason(bool $enabled, CashAiAutoApplyGateStats $stats): ?string
    {
        if (!$enabled) {
            return self::REASON_DISABLED;
        }
        if ($stats->decisions() < self::MIN_DECISIONS) {
            return self::REASON_TOO_FEW_DECISIONS;
        }
        if ($stats->precision() < self::MIN_PRECISION) {
            return self::REASON_LOW_PRECISION;
        }

        return null;
    }

    public static function suggestionQualifies(float $confidence, ?string $verdict): bool
    {
        return $confidence >= self::MIN_CONFIDENCE && 'accepted' === $verdict;
    }
}
