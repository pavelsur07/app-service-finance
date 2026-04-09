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
        $currentQuarter = (int) ceil((int) $today->format('n') / 3);
        $quarterLastMonth = $currentQuarter * 3;
        $defaultFrom = new \DateTimeImmutable($today->format('Y').'-01-01');
        $defaultTo = $today->setDate((int) $today->format('Y'), $quarterLastMonth, 1)->modify('last day of this month');

        $from = $fromParam ? new \DateTimeImmutable($fromParam) : $defaultFrom;
        $to = $toParam ? new \DateTimeImmutable($toParam) : $defaultTo;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new CashflowReportParams($company, $group, $from, $to);
    }
}
