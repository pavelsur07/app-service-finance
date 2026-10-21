<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

use App\Finance\Enum\PLCategoryType;

/**
 * Статья шаблона, которой у компании нет по коду, и статьи компании, которые с ней можно сопоставить.
 */
final readonly class PLTemplateMappingRow
{
    /**
     * @param list<PLTemplateMappingCandidate> $candidates статьи компании без кода того же типа
     * @param string|null $suggestedId похожая по названию статья компании
     */
    public function __construct(
        public string $code,
        public string $path,
        public PLCategoryType $type,
        public array $candidates,
        public ?string $suggestedId,
    ) {
    }
}
