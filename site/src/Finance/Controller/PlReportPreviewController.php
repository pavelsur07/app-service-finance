<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Finance\Report\PlReportGridBuilder;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Report\PlReportProjectsCompareBuilder;
use App\Repository\ProjectDirectionRepository;
use App\Sahred\Service\ActiveCompanyService;
use App\Service\Onboarding\AccountBootstrapper;
use App\Service\PLRegisterUpdater;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

        $fromInput = $request->query->get('from');
        $toInput = $request->query->get('to');

        $defaultStart = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0);
        $defaultEnd = (new \DateTimeImmutable('last day of this month'))->setTime(0, 0, 0);

        $from = $this->parseDate($fromInput) ?? $defaultStart;
        $to = $this->parseDate($toInput) ?? $defaultEnd;

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
}
