<?php

declare(strict_types=1);

namespace App\Budget\Enum;

enum BudgetVersionStatus: string
{
    case DRAFT = 'draft';
    case APPROVED = 'approved';
    case ARCHIVED = 'archived';

    /**
     * Черновик ↔ утверждён, черновик ↔ архив. Утверждённый в архив — только через черновик.
     */
    public function allowsTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT => self::DRAFT !== $target,
            self::APPROVED, self::ARCHIVED => self::DRAFT === $target,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::APPROVED => 'Утверждён',
            self::ARCHIVED => 'Архив',
        };
    }
}
