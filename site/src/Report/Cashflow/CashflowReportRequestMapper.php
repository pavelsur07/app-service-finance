<?php

namespace App\Report\Cashflow;

use App\Company\Application\DTO\FinancialResponsibilityCenterDTO;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\ProjectDirectionRepository;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

final class CashflowReportRequestMapper
{
    public function __construct(
        private readonly FinancialResponsibilityCenterFacade $responsibilityCenters,
        private readonly ProjectDirectionRepository $projectDirections,
    ) {
    }

    /**
     * @param list<ProjectDirection>|null $projectDirections
     * @param list<FinancialResponsibilityCenterDTO>|null $responsibilityCenters
     */
    public function fromRequest(
        Request $request,
        Company $company,
        ?array $projectDirections = null,
        ?array $responsibilityCenters = null,
    ): CashflowReportParams {
        $group = $request->query->get('group', 'month');
        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');

        $today = new \DateTimeImmutable('today');
        $currentQuarter = (int) ceil((int) $today->format('n') / 3);
        $quarterLastMonth = $currentQuarter * 3;
        $defaultFrom = new \DateTimeImmutable($today->format('Y').'-01-01');
        $defaultTo = $today->setDate((int) $today->format('Y'), $quarterLastMonth, 1)->modify('last day of this month');

        $from = $fromParam ? new \DateTimeImmutable($fromParam) : $defaultFrom;
        $to = $toParam ? new \DateTimeImmutable($toParam) : $defaultTo;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $projectListPresent = $request->query->has('projectDirectionIds');
        $projectFilterPresent = $projectListPresent || $request->query->getBoolean('projectFiltersPresent');
        $responsibilityCenterListPresent = $request->query->has('responsibilityCenterIds');
        $responsibilityCenterFilterPresent = $responsibilityCenterListPresent
            || $request->query->getBoolean('responsibilityCenterFiltersPresent');
        $query = $request->query->all();

        $projectDirectionIds = null;
        $availableProjectDirections = null;
        if ($projectFilterPresent) {
            $availableProjectDirections = $projectDirections ?? $this->projectDirections->findByCompany($company);
            $availableProjectIds = array_map(
                static fn ($project): string => (string) $project->getId(),
                $availableProjectDirections,
            );
            $projectDirectionIds = $this->resolveSelectedIds(
                $this->listParameter($query['projectDirectionIds'] ?? []),
                $availableProjectIds,
            );
            if (null !== $projectDirectionIds) {
                $projectDirectionIds = array_values(array_intersect($availableProjectIds, $projectDirectionIds));
            }
        }

        $responsibilityCenterIds = null;
        if ($responsibilityCenterFilterPresent) {
            $availableResponsibilityCenterIds = array_map(
                static fn ($center): string => (string) $center->id,
                $responsibilityCenters ?? $this->responsibilityCenters->getActiveChoices((string) $company->getId()),
            );
            $responsibilityCenterIds = $this->resolveSelectedIds(
                $this->listParameter($query['responsibilityCenterIds'] ?? []),
                $availableResponsibilityCenterIds,
            );
            if (null !== $responsibilityCenterIds) {
                $responsibilityCenterIds = array_values(array_intersect(
                    $availableResponsibilityCenterIds,
                    $responsibilityCenterIds,
                ));
            }
        }

        $responsibilityCenterId = $responsibilityCenterFilterPresent
            ? $this->singleId($responsibilityCenterIds)
            : $this->resolveResponsibilityCenterId(
                (string) $request->query->get('responsibilityCenterId', ''),
                $company,
            );

        return new CashflowReportParams(
            $company,
            $group,
            $from,
            $to,
            $responsibilityCenterId,
            $projectDirectionIds,
            $responsibilityCenterIds,
            $availableProjectDirections,
        );
    }

    /**
     * @param list<mixed> $requestedIds
     * @param list<string> $availableIds
     *
     * @return list<string>|null null means no restriction (all choices)
     */
    private function resolveSelectedIds(array $requestedIds, array $availableIds): ?array
    {
        $available = array_fill_keys($availableIds, true);
        $selected = [];
        foreach ($requestedIds as $requestedId) {
            if (!\is_string($requestedId) || !isset($available[$requestedId])) {
                continue;
            }

            $selected[$requestedId] = $requestedId;
        }
        $selectedIds = array_values($selected);

        if ([] === $availableIds) {
            return $selectedIds;
        }

        return \count($selectedIds) === \count($availableIds) ? null : $selectedIds;
    }

    /** @return list<mixed> */
    private function listParameter(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : [$value];
    }

    /** @param list<string>|null $ids */
    private function singleId(?array $ids): ?string
    {
        return 1 === \count($ids ?? []) ? $ids[0] : null;
    }

    private function resolveResponsibilityCenterId(string $responsibilityCenterId, Company $company): ?string
    {
        if ('' === $responsibilityCenterId || !Uuid::isValid($responsibilityCenterId)) {
            return null;
        }

        $center = $this->responsibilityCenters->findByIdAndCompany(
            $responsibilityCenterId,
            (string) $company->getId(),
        );

        return null !== $center && $center->isActive() ? $center->id : null;
    }
}
