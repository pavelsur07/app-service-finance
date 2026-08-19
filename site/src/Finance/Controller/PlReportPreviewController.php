<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Company\Application\Service\AccountBootstrapper;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\ProjectDirectionRepository;
use App\Company\Security\ModuleAccess;
use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Report\PlReportGridBuilder;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Report\PlReportProjectsCompareBuilder;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class PlReportPreviewController extends AbstractController
{
    #[Route('/finance/report/preview', name: 'finance_report_preview', methods: ['GET'])]
    public function preview(
        Request $request,
        ActiveCompanyService $activeCompany,
        AccountBootstrapper $accountBootstrapper,
        PlReportGridBuilder $gridBuilder,
        PlReportProjectsCompareBuilder $projectsCompareBuilder,
        ProjectDirectionRepository $projectDirections,
        FinancialResponsibilityCenterFacade $responsibilityCenters,
    ): Response {
        $company = $activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();

        $seeded = $accountBootstrapper->ensurePlSeeded($company);
        if ($seeded) {
            $this->addFlash(
                'info',
                'Для компании создана базовая структура ОПиУ. Настроить статьи можно в разделе "Справочники → ОПиУ (структура)".'
            );
        }

        $grouping = $request->query->get('grouping', 'month');
        if (!\in_array($grouping, ['day', 'week', 'month', 'quarter'], true)) {
            $grouping = 'month';
        }

        $layout = (string) $request->query->get('layout', 'periods');
        if (!in_array($layout, ['periods', 'projects'], true)) {
            $layout = 'periods';
        }

        $showMetaColumns = $request->query->getBoolean('show_meta');

        $projectDirectionsList = $projectDirections->findByCompany($company);
        $responsibilityCenterChoices = $responsibilityCenters->getActiveChoices($companyId);
        $filters = $this->resolveDimensionFilters($request, $projectDirectionsList, $responsibilityCenterChoices);
        $overheadProject = null;

        foreach ($projectDirectionsList as $pd) {
            $name = mb_strtolower(trim((string) $pd->getName()));
            if ('общий' === $name || str_starts_with($name, 'общий')) {
                $overheadProject = $pd;

                break;
            }
        }

        [$from, $to] = $this->resolveDateRange($request->query->get('from'), $request->query->get('to'));

        if ('projects' === $layout) {
            $compareProjects = $filters['plural']
                ? ($filters['projectFilter'] ?? $projectDirectionsList)
                : $projectDirectionsList;

            try {
                $compare = $projectsCompareBuilder->build(
                    $company,
                    $from,
                    $to,
                    $compareProjects,
                    $overheadProject,
                    $filters['responsibilityCenterFilter'],
                    $filters['plural'],
                );

                return $this->render('finance/report/preview.html.twig', [
                    'company' => $company,
                    'grouping' => $grouping,
                    'showMetaColumns' => $showMetaColumns,
                    'projectDirections' => $projectDirectionsList,
                    'selectedProjectDirectionId' => $this->singleId($filters['selectedProjectIds']),
                    'selectedProjectDirectionIds' => $filters['selectedProjectIds'],
                    'responsibilityCenters' => $responsibilityCenterChoices,
                    'selectedResponsibilityCenterId' => $this->singleId($filters['selectedResponsibilityCenterIds']),
                    'selectedResponsibilityCenterIds' => $filters['selectedResponsibilityCenterIds'],
                    'dimensionFiltersPresent' => $filters['plural'],
                    'from' => $from,
                    'to' => $to,
                    'layout' => $layout,
                    'periods' => [],
                    'rows' => $compare['rows'],
                    'warnings' => $compare['warnings'],
                    'compareProjects' => $compare['projects'],
                ]);
            } catch (\LogicException $e) {
                $warningMessage = 'Не удалось построить разрез по проектам. Проверьте наличие регистра pl_daily_totals по проектам.';
                $warnings = [$e->getMessage() ?: $warningMessage];

                $this->addFlash('warning', $warningMessage);

                return $this->render('finance/report/preview.html.twig', [
                    'company' => $company,
                    'grouping' => $grouping,
                    'showMetaColumns' => $showMetaColumns,
                    'projectDirections' => $projectDirectionsList,
                    'selectedProjectDirectionId' => $this->singleId($filters['selectedProjectIds']),
                    'selectedProjectDirectionIds' => $filters['selectedProjectIds'],
                    'responsibilityCenters' => $responsibilityCenterChoices,
                    'selectedResponsibilityCenterId' => $this->singleId($filters['selectedResponsibilityCenterIds']),
                    'selectedResponsibilityCenterIds' => $filters['selectedResponsibilityCenterIds'],
                    'dimensionFiltersPresent' => $filters['plural'],
                    'from' => $from,
                    'to' => $to,
                    'layout' => 'projects',
                    'periods' => [],
                    'rows' => [],
                    'warnings' => $warnings,
                    'compareProjects' => [],
                ]);
            }
        }

        $grid = $gridBuilder->build(
            $company,
            $from,
            $to,
            $grouping,
            $filters['projectFilter'],
            $filters['responsibilityCenterFilter'],
        );

        return $this->render('finance/report/preview.html.twig', [
            'company' => $company,
            'grouping' => $grouping,
            'showMetaColumns' => $showMetaColumns,
            'projectDirections' => $projectDirectionsList,
            'selectedProjectDirectionId' => $this->singleId($filters['selectedProjectIds']),
            'selectedProjectDirectionIds' => $filters['selectedProjectIds'],
            'responsibilityCenters' => $responsibilityCenterChoices,
            'selectedResponsibilityCenterId' => $this->singleId($filters['selectedResponsibilityCenterIds']),
            'selectedResponsibilityCenterIds' => $filters['selectedResponsibilityCenterIds'],
            'dimensionFiltersPresent' => $filters['plural'],
            'from' => $from,
            'to' => $to,
            'layout' => $layout,
            'periods' => array_map(
                static fn (PlReportPeriod $period): array => [
                    'id' => $period->id,
                    'label' => $period->label,
                    'from' => $period->from,
                    'to' => $period->to,
                ],
                $grid['periods']
            ),
            'rows' => $grid['rows'],
            'warnings' => $grid['warnings'],
        ]);
    }

    /**
     * Экспорт отчёта P&L в JSON для отладки и проверки.
     * Принимает те же query-параметры, что и /finance/report/preview.
     * Скачивает файл вида pl_report_2024-01-01_2024-03-31.json.
     */
    #[Route('/finance/report/preview/json', name: 'finance_report_preview_json', methods: ['GET'])]
    public function exportJson(
        Request $request,
        ActiveCompanyService $activeCompany,
        PlReportGridBuilder $gridBuilder,
        PlReportProjectsCompareBuilder $projectsCompareBuilder,
        ProjectDirectionRepository $projectDirections,
        FinancialResponsibilityCenterFacade $responsibilityCenters,
    ): JsonResponse {
        $company = $activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();

        $grouping = $request->query->get('grouping', 'month');
        if (!\in_array($grouping, ['day', 'week', 'month', 'quarter'], true)) {
            $grouping = 'month';
        }

        $layout = (string) $request->query->get('layout', 'periods');
        if (!in_array($layout, ['periods', 'projects'], true)) {
            $layout = 'periods';
        }

        $projectDirectionsList = $projectDirections->findByCompany($company);
        $filters = $this->resolveDimensionFilters(
            $request,
            $projectDirectionsList,
            $responsibilityCenters->getActiveChoices($companyId),
        );
        $overheadProject = null;

        foreach ($projectDirectionsList as $pd) {
            $name = mb_strtolower(trim((string) $pd->getName()));
            if ('общий' === $name || str_starts_with($name, 'общий')) {
                $overheadProject = $pd;
                break;
            }
        }

        [$from, $to] = $this->resolveDateRange($request->query->get('from'), $request->query->get('to'));

        if ('projects' === $layout) {
            $compareProjects = $filters['plural']
                ? ($filters['projectFilter'] ?? $projectDirectionsList)
                : $projectDirectionsList;

            try {
                $compare = $projectsCompareBuilder->build(
                    $company,
                    $from,
                    $to,
                    $compareProjects,
                    $overheadProject,
                    $filters['responsibilityCenterFilter'],
                    $filters['plural'],
                );
                $payload = [
                    'meta' => [
                        'company' => (string) $company->getName(),
                        'company_id' => (string) $company->getId(),
                        'from' => $from->format('Y-m-d'),
                        'to' => $to->format('Y-m-d'),
                        'layout' => 'projects',
                        'responsibility_center_id' => $filters['legacyResponsibilityCenterId'],
                        'project_direction_ids' => $filters['plural'] ? $filters['selectedProjectIds'] : null,
                        'responsibility_center_ids' => $filters['plural'] ? $filters['selectedResponsibilityCenterIds'] : null,
                        'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    ],
                    'projects' => $compare['projects'],
                    'rows' => $compare['rows'],
                    'warnings' => $compare['warnings'],
                ];
            } catch (\LogicException $e) {
                $payload = [
                    'meta' => [
                        'company' => (string) $company->getName(),
                        'company_id' => (string) $company->getId(),
                        'from' => $from->format('Y-m-d'),
                        'to' => $to->format('Y-m-d'),
                        'layout' => 'projects',
                        'responsibility_center_id' => $filters['legacyResponsibilityCenterId'],
                        'project_direction_ids' => $filters['plural'] ? $filters['selectedProjectIds'] : null,
                        'responsibility_center_ids' => $filters['plural'] ? $filters['selectedResponsibilityCenterIds'] : null,
                        'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    ],
                    'error' => $e->getMessage(),
                    'rows' => [],
                    'warnings' => [],
                ];
            }
        } else {
            $grid = $gridBuilder->build(
                $company,
                $from,
                $to,
                $grouping,
                $filters['projectFilter'],
                $filters['responsibilityCenterFilter'],
            );

            $payload = [
                'meta' => [
                    'company' => (string) $company->getName(),
                    'company_id' => (string) $company->getId(),
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d'),
                    'grouping' => $grouping,
                    'layout' => 'periods',
                    'project_direction_id' => $filters['legacyProjectId'],
                    'responsibility_center_id' => $filters['legacyResponsibilityCenterId'],
                    'project_direction_ids' => $filters['plural'] ? $filters['selectedProjectIds'] : null,
                    'responsibility_center_ids' => $filters['plural'] ? $filters['selectedResponsibilityCenterIds'] : null,
                    'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
                'periods' => array_map(
                    static fn (PlReportPeriod $period): array => [
                        'id' => $period->id,
                        'label' => $period->label,
                        'from' => $period->from->format('Y-m-d'),
                        'to' => $period->to->format('Y-m-d'),
                    ],
                    $grid['periods']
                ),
                'rows' => array_map(
                    static fn (array $row): array => [
                        'id' => $row['id'],
                        'code' => $row['code'],
                        'name' => $row['name'],
                        'level' => $row['level'],
                        'type' => $row['type'],
                        'values' => $row['values'],
                        'raw_values' => $grid['rawValues'][$row['id']] ?? [],
                    ],
                    $grid['rows']
                ),
                'warnings' => $grid['warnings'],
            ];
        }

        $filename = sprintf(
            'pl_report_%s_%s.json',
            $from->format('Y-m-d'),
            $to->format('Y-m-d')
        );

        $response = new JsonResponse($payload, Response::HTTP_OK, [], false);
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    #[Route('/finance/report/preview/recalc', name: 'finance_report_preview_recalc', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function recalc(
        Request $request,
        ActiveCompanyService $activeCompany,
        PLRegisterUpdater $registerUpdater,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('recalc_pl_preview', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен.');

            return $this->redirectToRoute('finance_report_preview', $this->previewRedirectParameters($request));
        }

        $company = $activeCompany->getActiveCompany();

        $fromInput = (string) $request->request->get('recalc_from');
        $toInput = (string) ($request->request->get('recalc_to') ?? $request->request->get('to'));

        try {
            $from = (new \DateTimeImmutable($fromInput))->setTime(0, 0, 0);
        } catch (\Throwable) {
            $this->addFlash('danger', 'Неверная дата начала пересчёта.');

            return $this->redirectToRoute('finance_report_preview', $this->previewRedirectParameters($request));
        }

        try {
            $to = $toInput
                ? (new \DateTimeImmutable((string) $toInput))->setTime(0, 0, 0)
                : (new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        } catch (\Throwable) {
            $this->addFlash('danger', 'Неверная дата окончания пересчёта.');

            return $this->redirectToRoute('finance_report_preview', $this->previewRedirectParameters($request));
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        try {
            $registerUpdater->recalcRange($company, $from, $to);
            $this->addFlash('success', sprintf(
                'Пересчёт P&L выполнен: %s — %s.',
                $from->format('d.m.Y'),
                $to->format('d.m.Y')
            ));
        } catch (\Throwable $exception) {
            $this->addFlash('danger', 'Ошибка пересчёта: '.$exception->getMessage());
        }

        return $this->redirectToRoute('finance_report_preview', $this->previewRedirectParameters(
            $request,
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        ));
    }

    /** @return array<string, mixed> */
    private function previewRedirectParameters(
        Request $request,
        ?string $defaultFrom = null,
        ?string $defaultTo = null,
    ): array {
        $parameters = [
            'grouping' => $request->request->get('grouping', 'month'),
            'from' => $request->request->get('from', $defaultFrom),
            'to' => $request->request->get('to', $defaultTo),
            'layout' => $request->request->get('layout', 'periods'),
            'show_meta' => $request->request->getBoolean('show_meta'),
            'projectDirectionId' => $request->request->get('projectDirectionId'),
            'responsibilityCenterId' => $request->request->get('responsibilityCenterId'),
        ];

        $submitted = $request->request->all();
        if ($request->request->getBoolean('dimensionFiltersPresent')) {
            $parameters['dimensionFiltersPresent'] = 1;
        }
        if ($request->request->getBoolean('projectFiltersPresent')) {
            $parameters['projectFiltersPresent'] = 1;
        }
        if ($request->request->getBoolean('responsibilityCenterFiltersPresent')) {
            $parameters['responsibilityCenterFiltersPresent'] = 1;
        }
        if ($request->request->has('projectDirectionIds')) {
            $parameters['projectDirectionIds'] = $this->listParameter($submitted['projectDirectionIds'] ?? []);
        }
        if ($request->request->has('responsibilityCenterIds')) {
            $parameters['responsibilityCenterIds'] = $this->listParameter($submitted['responsibilityCenterIds'] ?? []);
        }

        return $parameters;
    }

    /**
     * @param list<ProjectDirection> $projectDirections
     * @param list<object> $responsibilityCenters
     *
     * @return array{
     *     plural: bool,
     *     projectFilter: ProjectDirection|list<ProjectDirection>|null,
     *     responsibilityCenterFilter: string|list<string>|null,
     *     selectedProjectIds: list<string>|null,
     *     selectedResponsibilityCenterIds: list<string>|null,
     *     legacyProjectId: ?string,
     *     legacyResponsibilityCenterId: ?string,
     * }
     */
    private function resolveDimensionFilters(
        Request $request,
        array $projectDirections,
        array $responsibilityCenters,
    ): array {
        $projectById = [];
        foreach ($projectDirections as $projectDirection) {
            $projectById[(string) $projectDirection->getId()] = $projectDirection;
        }

        $responsibilityCenterIds = [];
        foreach ($responsibilityCenters as $responsibilityCenter) {
            $responsibilityCenterIds[] = (string) $responsibilityCenter->id;
        }

        $markerPresent = $request->query->getBoolean('dimensionFiltersPresent');
        $projectMarkerPresent = $markerPresent || $request->query->getBoolean('projectFiltersPresent');
        $responsibilityCenterMarkerPresent = $markerPresent
            || $request->query->getBoolean('responsibilityCenterFiltersPresent');
        $projectListPresent = $request->query->has('projectDirectionIds');
        $responsibilityCenterListPresent = $request->query->has('responsibilityCenterIds');
        $plural = $projectMarkerPresent
            || $responsibilityCenterMarkerPresent
            || $projectListPresent
            || $responsibilityCenterListPresent;

        if ($plural) {
            $query = $request->query->all();
            if (!$projectMarkerPresent && !$projectListPresent) {
                $legacyProjectId = (string) $request->query->get('projectDirectionId', '');
                $selectedProjectIds = isset($projectById[$legacyProjectId]) ? [$legacyProjectId] : null;
            } else {
                $selectedProjectIds = $this->resolveSelectedIds(
                    $this->listParameter($query['projectDirectionIds'] ?? []),
                    array_keys($projectById),
                );
            }
            if (!$responsibilityCenterMarkerPresent && !$responsibilityCenterListPresent) {
                $legacyResponsibilityCenterId = $this->resolveResponsibilityCenterId(
                    (string) $request->query->get('responsibilityCenterId', ''),
                    $responsibilityCenters,
                );
                $selectedResponsibilityCenterIds = $legacyResponsibilityCenterId
                    ? [$legacyResponsibilityCenterId]
                    : null;
            } else {
                $selectedResponsibilityCenterIds = $this->resolveSelectedIds(
                    $this->listParameter($query['responsibilityCenterIds'] ?? []),
                    $responsibilityCenterIds,
                );
            }

            return [
                'plural' => true,
                'projectFilter' => null === $selectedProjectIds
                    ? null
                    : array_map(static fn (string $id): ProjectDirection => $projectById[$id], $selectedProjectIds),
                'responsibilityCenterFilter' => $selectedResponsibilityCenterIds,
                'selectedProjectIds' => $selectedProjectIds,
                'selectedResponsibilityCenterIds' => $selectedResponsibilityCenterIds,
                'legacyProjectId' => $this->singleId($selectedProjectIds),
                'legacyResponsibilityCenterId' => $this->singleId($selectedResponsibilityCenterIds),
            ];
        }

        $projectDirectionId = (string) $request->query->get('projectDirectionId', '');
        $selectedProject = $projectById[$projectDirectionId] ?? null;
        $selectedResponsibilityCenterId = $this->resolveResponsibilityCenterId(
            (string) $request->query->get('responsibilityCenterId', ''),
            $responsibilityCenters,
        );

        return [
            'plural' => false,
            'projectFilter' => $selectedProject,
            'responsibilityCenterFilter' => $selectedResponsibilityCenterId,
            'selectedProjectIds' => $selectedProject ? [(string) $selectedProject->getId()] : null,
            'selectedResponsibilityCenterIds' => $selectedResponsibilityCenterId ? [$selectedResponsibilityCenterId] : null,
            // Keep the historical Preview JSON metadata behavior for an invalid singular project ID.
            'legacyProjectId' => '' !== $projectDirectionId ? $projectDirectionId : null,
            'legacyResponsibilityCenterId' => $selectedResponsibilityCenterId,
        ];
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

        // Selecting every visible choice is the unfiltered state, which also includes legacy unallocated facts.
        return \count($selectedIds) === \count($availableIds) ? null : $selectedIds;
    }

    /** @param list<string>|null $ids */
    private function singleId(?array $ids): ?string
    {
        return 1 === \count($ids ?? []) ? $ids[0] : null;
    }

    /** @return list<mixed> */
    private function listParameter(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : [$value];
    }

    /**
     * Возвращает [from, to] с дефолтным диапазоном = 01.01 — последний день текущего квартала.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function resolveDateRange(?string $fromInput, ?string $toInput): array
    {
        $now = new \DateTimeImmutable('today');
        $defaultStart = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);
        $currentQuarter = (int) ceil((int) $now->format('n') / 3);
        $quarterLastMonth = $currentQuarter * 3;
        $defaultEnd = $now->setDate((int) $now->format('Y'), $quarterLastMonth, 1)
            ->modify('last day of this month')->setTime(0, 0, 0);

        $from = $this->parseDate($fromInput) ?? $defaultStart;
        $to = $this->parseDate($toInput) ?? $defaultEnd;

        return [$from, $to];
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTime(0, 0, 0);
        } catch (\Exception) {
            return null;
        }
    }

    private function resolveResponsibilityCenterId(string $responsibilityCenterId, array $choices): ?string
    {
        if ('' === $responsibilityCenterId) {
            return null;
        }

        foreach ($choices as $choice) {
            if ($choice->id === $responsibilityCenterId) {
                return $choice->id;
            }
        }

        return null;
    }
}
