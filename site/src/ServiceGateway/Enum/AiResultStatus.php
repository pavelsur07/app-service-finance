<?php

declare(strict_types=1);

namespace App\ServiceGateway\Enum;

/**
 * Вердикт результата задачи ai-service (контракт `ai-task/v1`, `result_status`).
 */
enum AiResultStatus: string
{
    /** Проверки пройдены; применять можно только в режиме автоприменения компании. */
    case ACCEPTED = 'accepted';
    /** Показать человеку как подсказку. */
    case NEEDS_REVIEW = 'needs_review';
    /** Ответ модели отвергнут проверками; output нет. */
    case REJECTED = 'rejected';
    /** Техническая ошибка; задача остаётся человеку. */
    case FAILED = 'failed';
}
