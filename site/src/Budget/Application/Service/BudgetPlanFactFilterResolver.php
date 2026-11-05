<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Budget\Application\View\BudgetPlanFactFilter;
use App\Company\Application\DTO\FinancialResponsibilityCenterDTO;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\FinancialResponsibilityCenterFacade;

/**
 * Фильтр план–факта из адреса (`?projects[]=&centers[]=`; старый `?project=&center=` — списки из
 * одного значения). Неизвестные и чужие id отбрасываются; ничего не осталось или выбрано всё —
 * измерение не фильтруется. Проект — вместе с дочерними (как отчёт ОПиУ).
 */
final readonly class BudgetPlanFactFilterResolver
{
    public function __construct(
        private CompanyFacade $companies,
        private FinancialResponsibilityCenterFacade $responsibilityCenters,
    ) {
    }

    /**
     * @param list<string> $projectIds
     * @param list<string> $centerIds
     */
    public function resolve(string $companyId, array $projectIds, array $centerIds): BudgetPlanFactFilter
    {
        // Список проектов — в порядке дерева: потомки идут сразу за проектом и глубже него.
        $projects = $this->companies->listProjectDirections($companyId);
        $wanted = array_flip($projectIds);
        $selected = [];
        $expanded = [];
        $insideLevel = null;
        foreach ($projects as $project) {
            if (null !== $insideLevel && $project->level <= $insideLevel) {
                $insideLevel = null;
            }
            if (isset($wanted[$project->id])) {
                $selected[$project->id] = true;
                $insideLevel ??= $project->level;
            }
            if (null !== $insideLevel) {
                $expanded[] = $project->id;
            }
        }
        $projectsAll = [] === $selected || \count($expanded) === \count($projects);

        $centers = $this->responsibilityCenters->getActiveChoices($companyId);
        $wantedCenters = array_flip($centerIds);
        $selectedCenters = array_values(array_filter($centers, static fn (FinancialResponsibilityCenterDTO $center): bool => isset($wantedCenters[$center->id])));
        $centersAll = [] === $selectedCenters || \count($selectedCenters) === \count($centers);

        $projectOptions = [];
        foreach ($projects as $project) {
            $projectOptions[] = ['id' => $project->id, 'name' => $project->name, 'level' => $project->level, 'selected' => $projectsAll || isset($selected[$project->id])];
        }
        $centerOptions = [];
        $includeUnassigned = false;
        foreach ($centers as $center) {
            $on = $centersAll || isset($wantedCenters[$center->id]);
            $centerOptions[] = ['id' => $center->id, 'name' => $center->name, 'system' => $center->system, 'selected' => $on];
            $includeUnassigned = $includeUnassigned || ($on && $center->system);
        }

        return new BudgetPlanFactFilter(
            $projectOptions,
            $centerOptions,
            $projectsAll ? null : array_keys($selected),
            $projectsAll ? null : $expanded,
            $centersAll ? null : array_map(static fn (FinancialResponsibilityCenterDTO $center): string => $center->id, $selectedCenters),
            $includeUnassigned,
        );
    }
}
