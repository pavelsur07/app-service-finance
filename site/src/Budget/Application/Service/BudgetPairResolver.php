<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Budget\Application\DTO\BudgetPair;
use App\Budget\Exception\InvalidBudgetPairException;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\FinancialResponsibilityCenterFacade;

/**
 * Пара из запроса (`?project=&center=`): обе пустые — разрез «все пары» (null); иначе пара
 * должна быть активной парой компании, иначе InvalidBudgetPairException (422).
 */
final readonly class BudgetPairResolver
{
    public function __construct(
        private FinancialResponsibilityCenterFacade $responsibilityCenters,
        private CompanyFacade $companies,
    ) {
    }

    /**
     * Активные пары компании для выбора на экранах (подпись «Проект × ЦФО»), системная — помечена.
     *
     * @return list<array{projectDirectionId: string, responsibilityCenterId: string, label: string, system: bool}>
     */
    public function options(string $companyId): array
    {
        $projectNames = [];
        foreach ($this->companies->listProjectDirections($companyId) as $project) {
            $projectNames[$project->id] = $project->name;
        }
        $pairs = [];
        foreach ($this->responsibilityCenters->getActivePairs($companyId) as $pair) {
            if (!isset($projectNames[$pair->projectDirectionId])) {
                continue;
            }
            $pairs[] = [
                'projectDirectionId' => $pair->projectDirectionId,
                'responsibilityCenterId' => $pair->responsibilityCenterId,
                'label' => $projectNames[$pair->projectDirectionId].' × '.$pair->responsibilityCenterName,
                'system' => $pair->system,
            ];
        }

        return $pairs;
    }

    public function resolve(string $companyId, string $projectDirectionId, string $responsibilityCenterId): ?BudgetPair
    {
        if ('' === $projectDirectionId && '' === $responsibilityCenterId) {
            return null;
        }
        foreach ($this->responsibilityCenters->getActivePairs($companyId) as $pair) {
            if ($pair->projectDirectionId === $projectDirectionId && $pair->responsibilityCenterId === $responsibilityCenterId) {
                return new BudgetPair($projectDirectionId, $responsibilityCenterId);
            }
        }

        throw new InvalidBudgetPairException();
    }

    /**
     * ЦФО — системный «Общий» компании: факт без ЦФО Budget считает его (решение Владельца 04.11,
     * вариант A), поэтому факт такой пары шире фильтра отчёта ОПиУ по ЦФО.
     */
    public function isGeneralCenter(string $companyId, ?string $responsibilityCenterId): bool
    {
        if (null === $responsibilityCenterId || '' === $responsibilityCenterId) {
            return false;
        }

        $center = $this->responsibilityCenters->findByIdAndCompany($responsibilityCenterId, $companyId);

        return null !== $center && $center->system;
    }
}
