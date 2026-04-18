<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Enum;

/**
 * Статус сырого рекламного документа, загруженного из API маркетплейса.
 */
enum AdRawDocumentStatus: string
{
    case DRAFT = 'draft';
    case PROCESSED = 'processed';
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::PROCESSED => 'Обработан',
            self::FAILED => 'Ошибка',
        };
    }

    public function isDraft(): bool
    {
        return self::DRAFT === $this;
    }

    public function isTerminal(): bool
    {
        return self::PROCESSED === $this || self::FAILED === $this;
    }
}
