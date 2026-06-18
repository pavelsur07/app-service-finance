<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Domain\Event\NormalizationCompletedEvent;

final class NormalizationCompletedRecorder
{
    /**
     * @var list<NormalizationCompletedEvent>
     */
    private array $events = [];

    public function record(NormalizationCompletedEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<NormalizationCompletedEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
