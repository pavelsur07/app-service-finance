<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

final readonly class ApplyDefaultCostMappingCommand
{
    /**
     * @param bool $partial false — «всё или ничего», как у кнопки UI: правило без
     *                      статьи ОПиУ у компании блокирует весь набор.
     *                      true — для ночного прогона: такие правила уходят в
     *                      blocked, остальные применяются.
     */
    public function __construct(
        public string $companyId,
        public string $marketplace,
        public string $actorUserId,
        public bool $partial = false,
    ) {
    }
}
