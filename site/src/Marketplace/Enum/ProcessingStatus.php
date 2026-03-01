<?php

namespace App\Marketplace\Enum;

/**
 * ProcessingStatus - статусы обработки staging записей
 */
enum ProcessingStatus: string
{
    /**
     * Ожидает обработки (только что распарсена из raw document)
     */
    case PENDING = 'pending';

    /**
     * В процессе обработки (для избежания дублирования при параллельной обработке)
     */
    case PROCESSING = 'processing';

    /**
     * Успешно обработана (создана финальная сущность)
     */
    case COMPLETED = 'completed';

    /**
     * Обработка провалилась (ошибка валидации, не найден листинг и т.д.)
     */
    case FAILED = 'failed';

    /**
     * Пропущена (дубликат или другая причина)
     */
    case SKIPPED = 'skipped';

    /**
     * Получить все статусы, которые считаются "обработанными"
     */
    public static function getProcessedStatuses(): array
    {
        return [
            self::COMPLETED,
            self::FAILED,
            self::SKIPPED,
        ];
    }

    /**
     * Получить все статусы, которые можно переобработать
     */
    public static function getReprocessableStatuses(): array
    {
        return [
            self::FAILED,
        ];
    }

    /**
     * Получить человекочитаемое название
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает',
            self::PROCESSING => 'Обрабатывается',
            self::COMPLETED => 'Завершено',
            self::FAILED => 'Ошибка',
            self::SKIPPED => 'Пропущено',
        };
    }

    /**
     * Получить CSS класс для badge
     */
    public function getBadgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'badge-warning',
            self::PROCESSING => 'badge-info',
            self::COMPLETED => 'badge-success',
            self::FAILED => 'badge-danger',
            self::SKIPPED => 'badge-secondary',
        };
    }

    /**
     * Является ли статус финальным (не требует дальнейшей обработки)
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::SKIPPED,
        ], true);
    }

    /**
     * Можно ли переобработать запись с этим статусом
     */
    public function isReprocessable(): bool
    {
        return $this === self::FAILED;
    }
}
