<?php

declare(strict_types=1);

namespace App\OneC\Application\DTO;

/** Выгрузка (package_id) в карточке подключения: какие части уже приняты. */
final readonly class PackageSummary
{
    /**
     * @param list<PackagePart> $parts первые части выгрузки для ссылок на сырьё
     * @param list<string> $warnings
     */
    public function __construct(
        public string $packageId,
        public string $kind,
        public string $periodFrom,
        public string $periodTo,
        public int $partsTotal,
        public int $partsReceived,
        public array $parts,
        public array $warnings,
    ) {
    }
}
