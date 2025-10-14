<?php
declare(strict_types=1);

namespace App\Finance\Controller;

use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportPeriod;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class PlReportPreviewController extends AbstractController
{
    #[Route('/finance/report/preview', name: 'finance_report_preview', methods: ['GET'])]
    public function preview(
        Request $request,
        ActiveCompanyService $activeCompany,
        PlReportCalculator $calc
    ): Response {
        $company = $activeCompany->getActiveCompany();

        $grouping = $request->query->get('grouping', 'month');
        if (!\in_array($grouping, ['day', 'week', 'month'], true)) {
            $grouping = 'month';
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
            $result = $calc->calculate($company, $period);
            $results[] = $result;
            $warnings = array_merge($warnings, $result->warnings);
        }
        $warnings = array_values(array_unique($warnings));

        $rows = [];
        if ($results !== []) {
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

            if ($grouping === 'week') {
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
}
