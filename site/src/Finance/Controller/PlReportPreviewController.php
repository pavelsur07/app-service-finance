<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportPeriod;
use App\Repository\ProjectDirectionRepository;
use App\Service\ActiveCompanyService;
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
        PlReportCalculator $calc,
        ProjectDirectionRepository $projectDirectionRepo,
    ): Response {
        $company = $activeCompany->getActiveCompany();

        $grouping = $request->query->get('grouping', 'month');
        if (!\in_array($grouping, ['day', 'week', 'month'], true)) {
            $grouping = 'month';
        }

        $showMetaColumns = $request->query->getBoolean('show_meta');

        $projectDirectionId = $request->query->get('projectDirectionId');
        $projectDirections = $projectDirectionRepo->findByCompany($company);
        $selectedProject = null;
        if ($projectDirectionId) {
            foreach ($projectDirections as $projectDirection) {
                if ((string) $projectDirection->getId() === (string) $projectDirectionId) {
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

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $periods = $this->buildPeriods($from, $to, $grouping);

        $results = [];
        $warnings = [];
        foreach ($periods as $period) {
            $result = $calc->calculate($company, $period, $selectedProject);
            $results[] = $result;
            $warnings = array_merge($warnings, $result->warnings);
        }
        $warnings = array_values(array_unique($warnings));

        $rows = [];
        if ([] !== $results) {
            foreach ($results[0]->rows as $row) {
                $rows[$row->id] = [
                    'id' => $row->id,
                    'code' => $row->code,
                    'name' => $row->name,
                    'level' => $row->level,
                    'type' => $row->type,
                    'values' => [],
                ];
            }

            foreach ($results as $result) {
                foreach ($result->rows as $row) {
                    $rows[$row->id]['values'][$result->period->id] = $row->formatted;
                }
            }
        }

        return $this->render('finance/report/preview.html.twig', [
            'company' => $company,
            'grouping' => $grouping,
            'showMetaColumns' => $showMetaColumns,
            'projectDirections' => $projectDirections,
            'selectedProjectDirectionId' => $selectedProject?->getId(),
            'from' => $from,
            'to' => $to,
            'periods' => array_map(
                static fn (PlReportPeriod $period): array => [
                    'id' => $period->id,
                    'label' => $period->label,
                    'from' => $period->from,
                    'to' => $period->to,
                ],
                $periods
            ),
            'rows' => array_values($rows),
            'warnings' => $warnings,
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

    /** @return PlReportPeriod[] */
    private function buildPeriods(\DateTimeImmutable $from, \DateTimeImmutable $to, string $grouping): array
    {
        $periods = [];
        $start = $from->setTime(0, 0, 0);
        $endBound = $to->setTime(23, 59, 59);

        while ($start <= $endBound) {
            $periodStart = $start;
            switch ($grouping) {
                case 'day':
                    $candidateEnd = $periodStart;
                    $label = $periodStart->format('d.m.Y');
                    break;
                case 'week':
                    $candidateEnd = $periodStart->modify('sunday this week');
                    $label = '';
                    break;
                case 'month':
                default:
                    $candidateEnd = $periodStart->modify('last day of this month');
                    $label = $periodStart->format('Y-m');
                    break;
            }

            $candidateEnd = $candidateEnd->setTime(23, 59, 59);
            if ($candidateEnd > $endBound) {
                $periodEnd = $endBound;
            } else {
                $periodEnd = $candidateEnd;
            }

            if ('week' === $grouping) {
                $label = sprintf(
                    'Неделя %s (%s — %s)',
                    $periodStart->format('W'),
                    $periodStart->format('d.m.Y'),
                    $periodEnd->format('d.m.Y')
                );
            }

            $periods[] = new PlReportPeriod($periodStart, $periodEnd, $label);
            $start = $periodEnd->modify('+1 day')->setTime(0, 0, 0);
        }

        return $periods;
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
            'show_meta' => $request->request->getBoolean('show_meta'),
            'projectDirectionId' => $request->request->get('projectDirectionId'),
        ]);
    }
}
