<?php
declare(strict_types=1);

namespace App\Finance\Engine;

use App\Entity\PLCategory;

final class TopoSort
{
    /** @param array<string,PLCategory> $byId */
    public function sort(Graph $g, array $byId): array
    {
        return $g->topoSort(function(string $a, string $b) use ($byId){
            $A = $byId[$a] ?? null; $B = $byId[$b] ?? null;
            $ao = $A?->getCalcOrder() ?? 0; $bo = $B?->getCalcOrder() ?? 0;
            if ($ao === $bo) {
                $as = $A?->getSortOrder() ?? 0; $bs = $B?->getSortOrder() ?? 0;
                return $as <=> $bs;
            }
            return $ao <=> $bo;
        });
    }
}
