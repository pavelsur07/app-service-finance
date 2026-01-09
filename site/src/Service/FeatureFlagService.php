<?php

namespace App\Service;

class FeatureFlagService
{
    public function __construct(private readonly bool $fundsAndWidgetEnabled)
    {
    }

    public function isFundsAndWidgetEnabled(): bool
    {
        return $this->fundsAndWidgetEnabled;
    }
}
