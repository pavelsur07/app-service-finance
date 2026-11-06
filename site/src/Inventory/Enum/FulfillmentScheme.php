<?php

declare(strict_types=1);

namespace App\Inventory\Enum;

/**
 * Схема хранения в отчёте остатков, одинаковая для всех источников:
 * FBO — товар на складе маркетплейса, FBS — товар на складе продавца.
 */
enum FulfillmentScheme: string
{
    case Fbo = 'fbo';
    case Fbs = 'fbs';

    /** Типы склада (fulfillment_type), которые относятся к складу продавца. Всё остальное — FBO. */
    public const FBS_TYPES = ['fbs', 'rfbs'];

    public function label(): string
    {
        return match ($this) {
            self::Fbo => 'FBO',
            self::Fbs => 'FBS',
        };
    }

    public static function fromFulfillmentType(?string $fulfillmentType): self
    {
        return in_array(mb_strtolower(trim((string) $fulfillmentType)), self::FBS_TYPES, true) ? self::Fbs : self::Fbo;
    }
}
