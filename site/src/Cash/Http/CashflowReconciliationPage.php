<?php

declare(strict_types=1);

namespace App\Cash\Http;

use App\Cash\Application\Report\BuildCashflowReconciliation;
use App\Cash\Application\Report\CashflowGroupReportRequest;
use App\Cash\Application\Report\IntragroupTaxIds;
use App\Cash\Infrastructure\Query\CashflowGroupSnapshot;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class CashflowReconciliationPage
{
    public function __construct(
        private CashflowGroupReportRequest $reportRequest,
        private CashflowGroupSnapshot $snapshot,
        private IntragroupTaxIds $taxIds,
        private BuildCashflowReconciliation $reconcile,
    ) {
    }

    /** @return array<string, mixed> */
    public function context(Request $request, Company $company, User $user): array
    {
        return $this->snapshot->read(fn (): array => $this->load($request, $company, $user));
    }

    /** @return array<string, mixed> */
    private function load(Request $request, Company $company, User $user): array
    {
        $report = ($this->reportRequest)($request, $company, $user);
        $base = ['report' => $report, 'companyName' => $company->getName()];
        if (null === $report) {
            return $base;
        }
        $members = array_keys($report->companies);
        \assert([] !== $members);
        $rec = $report->reconciliation;
        $selectedRow = null;
        $shownPairs = $rec->pairs;
        $row = $request->query->getString('row');
        if ('' !== $row) {
            $lines = array_column([...$report->lines, $report->unmatched, $report->totals], null, 'key');
            $selectedRow = $lines[$row] ?? throw new NotFoundHttpException('Статья отсутствует в этом отчёте.');
            $shownPairs = array_intersect_key($rec->pairs, array_flip($selectedRow->pairIds));
        }
        $match = $request->query->getString('match');
        $selected = '' === $match ? null : ($rec->unpaired[$match] ?? throw new NotFoundHttpException('Операция отсутствует среди несопоставленных.'));
        $choices = null === $selected ? [] : $this->reconcile->manualCandidates($members, $selected);

        $operationId = $request->query->getString('operation');
        $selectedOperation = null;
        if ('' !== $operationId) {
            $visiblePairs = $rec->pairs + $rec->inTransit;
            if (!isset($rec->unpaired[$operationId]) && !isset($visiblePairs[$operationId]) && !\in_array($operationId, $visiblePairs, true)) {
                throw new NotFoundHttpException('Операция отсутствует в этой сверке.');
            }
            $selectedOperation = $rec->transactions[$operationId];
        }

        $formIds = [];
        foreach (array_keys($shownPairs + $rec->inTransit) as $id) {
            $formIds[$id] = Uuid::uuid7()->toString();
        }
        if (null !== $selected) {
            $formIds[$selected->id] = Uuid::uuid7()->toString();
        }

        return $base + [
            'period' => ['from' => $report->from->format('Y-m-d'), 'to' => $report->to->format('Y-m-d')],
            'selectedRow' => $selectedRow, 'shownPairs' => $shownPairs, 'selected' => $selected,
            'choices' => $choices, 'selectedOperation' => $selectedOperation, 'formIds' => $formIds,
            'candidates' => $this->taxIds->candidates($members, $report->from, $report->to),
            'difference' => bcsub($rec->income, $rec->expense, 2),
            'explainedNet' => bcadd($rec->unpairedNet, $rec->transitNet, 2),
        ];
    }
}
