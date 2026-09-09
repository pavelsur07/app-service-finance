<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Состояние аутентификации подключения к маркетплейсу.
 *
 * Отдельно от `isActive`: активность выключает Владелец, а это состояние
 * выставляет конвейер, когда маркетплейс перестал принимать ключ. Смешивать их
 * нельзя — выключенное Владельцем подключение и подключение с протухшим ключом
 * требуют разных действий и разных текстов в кабинете.
 */
enum MarketplaceConnectionAuthStatus: string
{
    case OK = 'ok';
    case FAILED = 'failed';

    public function isFailed(): bool
    {
        return self::FAILED === $this;
    }

    public function getDisplayName(): string
    {
        return match ($this) {
            self::OK => 'Ключ принимается',
            self::FAILED => 'Ключ не принимается',
        };
    }
}
