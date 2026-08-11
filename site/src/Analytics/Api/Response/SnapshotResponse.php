<?php

namespace App\Analytics\Api\Response;

final readonly class SnapshotResponse
{
    public function __construct(
        private SnapshotContextResponse $context,
        private ?FreeCashWidgetResponse $freeCash = null,
        private ?InflowWidgetResponse $inflow = null,
        /** @var array<string, mixed> */
        private array $outflow = [],
        /** @var array<string, mixed> */
        private array $cashflowSplit = [],
        private ?RevenueWidgetResponse $revenue = null,
        /** @var array<string, mixed> */
        private array $profit = [],
        /** @var array<string, mixed> */
        private array $topCash = [],
        /** @var array<string, mixed> */
        private array $topPnl = [],
        /** @var list<array{code: string}> */
        private array $alerts = [],
        /** @var list<array{code: string, message: string}> */
        private array $warnings = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $widgets = [
            'alerts' => [
                'items' => $this->alerts,
            ],
            'warnings' => [
                'items' => $this->warnings,
            ],
        ];

        if (null !== $this->freeCash) {
            $widgets['free_cash'] = $this->freeCash->toArray();
        }
        if (null !== $this->inflow) {
            $widgets['inflow'] = $this->inflow->toArray();
        }
        if ([] !== $this->outflow) {
            $widgets['outflow'] = $this->outflow;
        }
        if ([] !== $this->cashflowSplit) {
            $widgets['cashflow_split'] = $this->cashflowSplit;
        }
        if (null !== $this->revenue) {
            $widgets['revenue'] = $this->revenue->toArray();
        }
        if ([] !== $this->topCash) {
            $widgets['top_cash'] = $this->topCash;
        }
        if ([] !== $this->topPnl) {
            $widgets['top_pnl'] = $this->topPnl;
        }
        if ([] !== $this->profit) {
            $widgets['profit'] = $this->profit;
        }

        return [
            'context' => $this->context->toArray(),
            'widgets' => $widgets,
        ];
    }
}
