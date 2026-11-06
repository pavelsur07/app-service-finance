<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Entity\SettlementMovement;
use App\Settlement\Entity\SettlementSourceLink;

final readonly class SettlementRegisterProjection
{
    /** @param list<SettlementMovement> $movements
     * @param list<SettlementSourceLink> $links
     * @param array<string,SettlementDocument> $activeSources
     */
    public function __construct(public array $movements, public array $links, public array $activeSources, public \DateTimeImmutable $startDate)
    {
    }
}
