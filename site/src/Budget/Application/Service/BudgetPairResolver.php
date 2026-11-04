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
     * @return list<array{projectDirectionId: string, responsibilityCenterId: string, label: string, projectName: string, centerName: string, system: bool}>
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
                'projectName' => $projectNames[$pair->projectDirectionId],
                'centerName' => (string) $pair->responsibilityCenterName,
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
     * Разрез сетки (`?project=&center=`): пусто или `all` — все по измерению. Обе заданы — активная
     * пара; одна — значение должно встречаться в активных парах, иначе InvalidBudgetPairException.
     *
     * @return array{0: ?string, 1: ?string} [projectDirectionId, responsibilityCenterId]
     */
    public function resolveSlice(string $companyId, string $projectDirectionId, string $responsibilityCenterId): array
    {
        $project = '' === $projectDirectionId || 'all' === $projectDirectionId ? null : $projectDirectionId;
        $center = '' === $responsibilityCenterId || 'all' === $responsibilityCenterId ? null : $responsibilityCenterId;
        if (null === $project && null === $center) {
            return [null, null];
        }
        if (null !== $project && null !== $center) {
            $pair = $this->resolve($companyId, $project, $center);

            return [$pair?->projectDirectionId, $pair?->responsibilityCenterId];
        }
        foreach ($this->responsibilityCenters->getActivePairs($companyId) as $pair) {
            if ((null !== $project && $pair->projectDirectionId === $project) || (null !== $center && $pair->responsibilityCenterId === $center)) {
                return [$project, $center];
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
