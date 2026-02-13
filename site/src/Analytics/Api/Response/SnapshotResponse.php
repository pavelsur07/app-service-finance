<?php

namespace App\Analytics\Api\Response;

final readonly class SnapshotResponse
{
    public function __construct(
        private SnapshotContextResponse $context,
        private FreeCashWidgetResponse $freeCash,
        private InflowWidgetResponse $inflow,
        private RevenueWidgetResponse $revenue,
        /** @var array<string, mixed> */
        private array $profit,
        /** @var list<string> */
        private array $alerts = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'context' => $this->context->toArray(),
            'widgets' => [
                'free_cash' => $this->freeCash->toArray(),
                'inflow' => $this->inflow->toArray(),
                'outflow' => new \stdClass(),
                'cashflow_split' => new \stdClass(),
                'revenue' => $this->revenue->toArray(),
                'top_cash' => new \stdClass(),
                'top_pnl' => new \stdClass(),
                'profit' => $this->profit,
                'alerts' => ['warnings' => $this->alerts],
            ],
        ];
    }
}
