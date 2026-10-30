<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

/**
 * Жизненный цикл ИИ-подсказки статьи ДДС (docs/tasks/ai/stages/stage-2.md).
 *
 * requested → suggested | no_answer | failed | stale; suggested → accepted | dismissed | stale.
 */
enum CashCategorySuggestionStatus: string
{
    /** Задача поставлена, ответа ещё нет. */
    case REQUESTED = 'requested';
    /** Статья предложена и показывается человеку. */
    case SUGGESTED = 'suggested';
    /** Модель ответила «недостаточно данных». */
    case NO_ANSWER = 'no_answer';
    /** Технический сбой или ответ отвергнут проверками сервиса; операцию можно запросить снова. */
    case FAILED = 'failed';
    /** Ответ устарел: операция уже получила статью, удалена, или статья недоступна. */
    case STALE = 'stale';
    case ACCEPTED = 'accepted';
    case DISMISSED = 'dismissed';

    /** Открытые статусы держат частичный unique: не больше одной подсказки на операцию. */
    public const OPEN = [self::REQUESTED, self::SUGGESTED];
}
