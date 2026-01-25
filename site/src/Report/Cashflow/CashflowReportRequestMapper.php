<?php

namespace App\Report\Cashflow;

use App\Company\Entity\Company;
use Symfony\Component\HttpFoundation\Request;

final class CashflowReportRequestMapper
{
    public function fromRequest(Request $request, Company $company): CashflowReportParams
    {
        $group = $request->query->get('group', 'month');
        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');

        $today = new \DateTimeImmutable('today');
        $currentQuarter = (int) floor(((int) $today->format('n') - 1) / 3);
        $quarterStartMonth = $currentQuarter * 3 + 1;
        $defaultFrom = new \DateTimeImmutable($today->format('Y').'-'.sprintf('%02d', $quarterStartMonth).'-01');
        $defaultTo = $defaultFrom->modify('+3 months -1 day');

        $from = $fromParam ? new \DateTimeImmutable($fromParam) : $defaultFrom;
        $to = $toParam ? new \DateTimeImmutable($toParam) : $defaultTo;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new CashflowReportParams($company, $group, $from, $to);
    }
}
