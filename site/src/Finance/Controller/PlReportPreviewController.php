<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Finance\Report\PlReportGridBuilder;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Report\PlReportProjectsCompareBuilder;
use App\Repository\ProjectDirectionRepository;
use App\Service\Onboarding\AccountBootstrapper;
use App\Service\PLRegisterUpdater;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

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
    ): Response {
        $company = $activeCompany->getActiveCompany();

        $seeded = $accountBootstrapper->ensurePlSeeded($company);
        if ($seeded) {
            $this->addFlash(
                'info',
                'Для компании создана базовая структура ОПиУ. Настроить статьи можно в разделе "Справочники → ОПиУ (структура)".'
            );
        }

        $grouping = $request->query->get('grouping', 'month');
        if (!\in_array($grouping, ['day', 'week', 'month'], true)) {
            $grouping = 'month';
        }

        $layout = (string) $request->query->get('layout', 'periods');
        if (!in_array($layout, ['periods', 'projects'], true)) {
            $layout = 'periods';
        }

        $showMetaColumns = $request->query->getBoolean('show_meta');

        $projectDirectionId = (string) $request->query->get('projectDirectionId', '');
        $projectDirectionsList = $projectDirections->findByCompany($company);
        $overheadProject = null;

        foreach ($projectDirectionsList as $pd) {
            $name = mb_strtolower(trim((string) $pd->getName()));
            if ('общий' === $name || str_starts_with($name, 'общий')) {
                $overheadProject = $pd;

                break;
            }
        }

        $selectedProject = null;
        if ('' !== $projectDirectionId) {
            foreach ($projectDirectionsList as $projectDirection) {
                if ((string) $projectDirection->getId() === $projectDirectionId) {
                    $selectedProject = $projectDirection;

                    break;
                }
            }
        }

        [$from, $to] = $this->resolveDateRange($request->query->get('from'), $request->query->get('to'));

        if ('projects' === $layout) {
            try {
                $compare = $projectsCompareBuilder->build($company, $from, $to, $projectDirectionsList, $overheadProject);

                return $this->render('finance/report/preview.html.twig', [
                    'company' => $company,
                    'grouping' => $grouping,
                    'showMetaColumns' => $showMetaColumns,
                    'projectDirections' => $projectDirectionsList,
                    'selectedProjectDirectionId' => $selectedProject?->getId(),
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
                    'selectedProjectDirectionId' => $selectedProject?->getId(),
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

        $grid = $gridBuilder->build($company, $from, $to, $grouping, $selectedProject);

        return $this->render('finance/report/preview.html.twig', [
            'company' => $company,
            'grouping' => $grouping,
            'showMetaColumns' => $showMetaColumns,
            'projectDirections' => $projectDirectionsList,
            'selectedProjectDirectionId' => $selectedProject?->getId(),
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
     * Скачивает файл вида pl_report_2024-01-01_2024-03-31.json
     */
    #[Route('/finance/report/preview/json', name: 'finance_report_preview_json', methods: ['GET'])]
    public function exportJson(
        Request $request,
        ActiveCompanyService $activeCompany,
        PlReportGridBuilder $gridBuilder,
        PlReportProjectsCompareBuilder $projectsCompareBuilder,
        ProjectDirectionRepository $projectDirections,
    ): JsonResponse {
        $company = $activeCompany->getActiveCompany();

        $grouping = $request->query->get('grouping', 'month');
        if (!\in_array($grouping, ['day', 'week', 'month'], true)) {
            $grouping = 'month';
        }

        $layout = (string) $request->query->get('layout', 'periods');
        if (!in_array($layout, ['periods', 'projects'], true)) {
            $layout = 'periods';
        }

        $projectDirectionId = (string) $request->query->get('projectDirectionId', '');
        $projectDirectionsList = $projectDirections->findByCompany($company);
        $overheadProject = null;

        foreach ($projectDirectionsList as $pd) {
            $name = mb_strtolower(trim((string) $pd->getName()));
            if ('общий' === $name || str_starts_with($name, 'общий')) {
                $overheadProject = $pd;
                break;
            }
        }

        $selectedProject = null;
        if ('' !== $projectDirectionId) {
            foreach ($projectDirectionsList as $projectDirection) {
                if ((string) $projectDirection->getId() === $projectDirectionId) {
                    $selectedProject = $projectDirection;
                    break;
                }
            }
        }

        [$from, $to] = $this->resolveDateRange($request->query->get('from'), $request->query->get('to'));

        if ('projects' === $layout) {
            try {
                $compare = $projectsCompareBuilder->build($company, $from, $to, $projectDirectionsList, $overheadProject);
                $payload = [
                    'meta' => [
                        'company' => (string) $company->getName(),
                        'company_id' => (string) $company->getId(),
                        'from' => $from->format('Y-m-d'),
                        'to' => $to->format('Y-m-d'),
                        'layout' => 'projects',
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
                        'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    ],
                    'error' => $e->getMessage(),
                    'rows' => [],
                    'warnings' => [],
                ];
            }
        } else {
            $grid = $gridBuilder->build($company, $from, $to, $grouping, $selectedProject);

            $payload = [
                'meta' => [
                    'company' => (string) $company->getName(),
                    'company_id' => (string) $company->getId(),
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d'),
                    'grouping' => $grouping,
                    'layout' => 'periods',
                    'project_direction_id' => $projectDirectionId ?: null,
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
    public function recalc(
        Request $request,
        ActiveCompanyService $activeCompany,
        PLRegisterUpdater $registerUpdater,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('recalc_pl_preview', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен.');

            return $this->redirectToRoute('finance_report_preview', [
                'grouping' => $request->request->get('grouping', 'month'),
                'from' => $request->request->get('from'),
                'to' => $request->request->get('to'),
                'layout' => $request->request->get('layout', 'periods'),
                'show_meta' => $request->request->getBoolean('show_meta'),
                'projectDirectionId' => $request->request->get('projectDirectionId'),
            ]);
        }

        $company = $activeCompany->getActiveCompany();

        $fromInput = (string) $request->request->get('recalc_from');
        $toInput = (string) ($request->request->get('recalc_to') ?? $request->request->get('to'));

        try {
            $from = (new \DateTimeImmutable($fromInput))->setTime(0, 0, 0);
        } catch (\Throwable) {
            $this->addFlash('danger', 'Неверная дата начала пересчёта.');

            return $this->redirectToRoute('finance_report_preview', [
                'grouping' => $request->request->get('grouping', 'month'),
                'from' => $request->request->get('from'),
                'to' => $request->request->get('to'),
                'layout' => $request->request->get('layout', 'periods'),
                'show_meta' => $request->request->getBoolean('show_meta'),
                'projectDirectionId' => $request->request->get('projectDirectionId'),
            ]);
        }

        try {
            $to = $toInput
                ? (new \DateTimeImmutable((string) $toInput))->setTime(0, 0, 0)
                : (new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        } catch (\Throwable) {
            $this->addFlash('danger', 'Неверная дата окончания пересчёта.');

            return $this->redirectToRoute('finance_report_preview', [
                'grouping' => $request->request->get('grouping', 'month'),
                'from' => $request->request->get('from'),
                'to' => $request->request->get('to'),
                'layout' => $request->request->get('layout', 'periods'),
                'show_meta' => $request->request->getBoolean('show_meta'),
                'projectDirectionId' => $request->request->get('projectDirectionId'),
            ]);
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

        return $this->redirectToRoute('finance_report_preview', [
            'grouping' => $request->request->get('grouping', 'month'),
            'from' => $request->request->get('from', $from->format('Y-m-d')),
            'to' => $request->request->get('to', $to->format('Y-m-d')),
            'layout' => $request->request->get('layout', 'periods'),
            'show_meta' => $request->request->getBoolean('show_meta'),
            'projectDirectionId' => $request->request->get('projectDirectionId'),
        ]);
    }

    /**
     * Возвращает [from, to] с дефолтным диапазоном = текущий квартал.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function resolveDateRange(?string $fromInput, ?string $toInput): array
    {
        $currentMonth = new \DateTimeImmutable('first day of this month');
        $quarterStartMonth = ((int) $currentMonth->format('n') - 1) - (((int) $currentMonth->format('n') - 1) % 3) + 1;
        $quarterStart = $currentMonth->setDate((int) $currentMonth->format('Y'), $quarterStartMonth, 1);
        $defaultStart = $quarterStart->setTime(0, 0, 0);
        $defaultEnd = $quarterStart->modify('+2 months')->modify('last day of this month')->setTime(0, 0, 0);

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
}
