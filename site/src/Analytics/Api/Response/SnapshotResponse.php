<?php

namespace App\Analytics\Api\Response;

final readonly class SnapshotResponse
{
    public function __construct(
        private SnapshotContextResponse $context,
        private FreeCashWidgetResponse $freeCash,
        private InflowWidgetResponse $inflow,
        /** @var array<string, mixed> */
        private array $outflow,
        /** @var array<string, mixed> */
        private array $cashflowSplit,
        private RevenueWidgetResponse $revenue,
        /** @var array<string, mixed> */
        private array $profit,
        /** @var array<string, mixed> */
        private array $topCash,
        /** @var list<array{code: string}> */
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
                'outflow' => $this->outflow,
                'cashflow_split' => $this->cashflowSplit,
                'revenue' => $this->revenue->toArray(),
                'top_cash' => $this->topCash,
                'top_pnl' => new \stdClass(),
                'profit' => $this->profit,
                'alerts' => [
                    'items' => $this->alerts,
                    // keep legacy field for compatibility with existing clients
                    'warnings' => array_map(
                        static fn (array $alert): string => (string) ($alert['code'] ?? ''),
                        $this->alerts,
                    ),
                ],
            ],
        ];
    }
}
