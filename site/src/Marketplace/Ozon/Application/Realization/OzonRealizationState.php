<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Realization;

/**
 * Состояние отчёта «Реализация» за месяц для показа пользователю. `tone` — success | warning | danger | secondary.
 */
final readonly class OzonRealizationState
{
    public function __construct(
        public string $tone,
        public string $text,
    ) {
    }
}
