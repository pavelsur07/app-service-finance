<?php

declare(strict_types=1);

namespace App\MarketplaceQuality\Application\DTO;

/** Проблемный день: звено и сумма расхождения (null — день не переведён). */
final readonly class QualityProblem
{
    public const UNTRANSLATED = 'untranslated';
    public const QUARANTINE = 'quarantine';
    public const TRANSLATION_CANON = 'translation_canon';
    public const CANON_MART = 'canon_mart';

    public function __construct(
        public string $connectionRef,
        public string $date,
        public string $link,
        public ?int $diffMinor,
    ) {
    }
}
