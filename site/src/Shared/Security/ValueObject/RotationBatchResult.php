<?php

declare(strict_types=1);

namespace App\Shared\Security\ValueObject;

final readonly class RotationBatchResult
{
    /**
     * @param int $selected секретов на старой версии в выбранной порции
     * @param int $rotated из них перешифровано; остальные изменились параллельно
     */
    public function __construct(
        public int $selected,
        public int $rotated,
    ) {
    }
}
