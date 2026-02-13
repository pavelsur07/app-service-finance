<?php

namespace App\Analytics\Api\Response;

final readonly class SnapshotResponse
{
    public function __construct(
        private SnapshotContextResponse $context,
        private FreeCashWidgetResponse $freeCash,
        private InflowWidgetResponse $inflow,
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
            ],
        ];
    }
}
