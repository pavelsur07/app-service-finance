<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

/**
 * Схема исполнения заказа.
 *
 * Хранится отдельно от источника, потому что наборы статусов у схем разные:
 * без явного различения нормализация превратилась бы в угадывание.
 */
enum IngestOrderScheme: string
{
    /** Со склада маркетплейса. */
    case FBO = 'fbo';

    /** Со склада продавца. */
    case FBS = 'fbs';

    public function label(): string
    {
        return match ($this) {
            self::FBO => 'Со склада маркетплейса',
            self::FBS => 'Со склада продавца',
        };
    }
}
